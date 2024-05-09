<?php

namespace Oscmarb\CriteriaDoctrineConverter;

use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\Query\Expr\Func;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use Oscmarb\Criteria\Criteria;
use Oscmarb\Criteria\Filter\Condition\ConditionFilter;
use Oscmarb\Criteria\Filter\Condition\FilterField;
use Oscmarb\Criteria\Filter\Condition\FilterOperator;
use Oscmarb\Criteria\Filter\Filter;
use Oscmarb\Criteria\Filter\Logic\AndFilter;
use Oscmarb\Criteria\Filter\Logic\OrFilter;
use Oscmarb\Criteria\Order\CriteriaOrderBy;
use Oscmarb\Criteria\Order\CriteriaOrders;
use Oscmarb\Criteria\Pagination\CriteriaLimit;
use Oscmarb\Criteria\Pagination\CriteriaOffset;

class CriteriaDoctrineConverter
{
    private int $parameterCount;

    public static function create(QueryBuilder $queryBuilder, array $fields): self
    {
        return new self($queryBuilder, $fields);
    }

    /**
     * @param array<string, string> $fields
     */
    protected function __construct(
        private QueryBuilder $queryBuilder,
        private array        $fields
    )
    {
        $this->queryBuilder = clone $queryBuilder;
        $this->parameterCount = $this->queryBuilder->getParameters()->count() + 1;
    }

    public function convert(Criteria $criteria): QueryBuilder
    {
        $filters = $criteria->filters()->values();

        if (1 >= count($filters)) {
            $filter = array_values($filters)[0] ?? null;
        } else {
            $filter = AndFilter::create(...$filters);
        }

        if (null !== $filter) {
            $this->queryBuilder->andWhere($this->formatFilter($filter));
        }

        $this->addOrdersToBuilder($criteria->orders());
        $this->addOffsetToBuilder($criteria->offset());
        $this->addLimitToBuilder($criteria->limit());

        return $this->queryBuilder;
    }

    protected function addOrdersToBuilder(CriteriaOrders $orders): void
    {
        $orders = $orders->values();

        foreach ($orders as $order) {
            $this->queryBuilder->addOrderBy($this->mapOrderBy($order->orderBy()), $order->orderType()->value());
        }
    }

    protected function addOffsetToBuilder(?CriteriaOffset $offset): void
    {
        $offset = $offset?->value();

        if (null === $offset) {
            return;
        }

        $this->queryBuilder->setFirstResult($offset);
    }

    protected function addLimitToBuilder(?CriteriaLimit $limit): void
    {
        $limit = $limit?->value();

        if (null === $limit) {
            return;
        }

        $this->queryBuilder->setMaxResults($limit);
    }

    protected function formatFilter(Filter $filter): Andx|Orx|Comparison|Func|string
    {
        if (true === $filter instanceof ConditionFilter) {
            return $this->formatCondition($filter);
        }

        $isAndFilter = $filter instanceof AndFilter;

        if (true === $isAndFilter || true === $filter instanceof OrFilter) {
            /** @var AndFilter|OrFilter $filter */
            $subFilters = array_map(fn(Filter $filter) => $this->formatFilter($filter), $filter->filters());

            return true === $isAndFilter
                ? $this->queryBuilder->expr()->andX(...$subFilters)
                : $this->queryBuilder->expr()->orX(...$subFilters);
        }

        throw new \RuntimeException('Unknown filter type');
    }

    protected function formatCondition(ConditionFilter $filter): Andx|Comparison|Func|string
    {
        $operator = $filter->operator();
        $isEqualsOrNotEqualsOperator = $operator->isEqual() || $operator->isNotEqual();

        if (true === $isEqualsOrNotEqualsOperator && null === $filter->value()->value()) {
            return $this->formatNullableConditionExpression($filter);
        }

        return $this->formatBasicCondition($filter);
    }

    protected function formatBasicCondition(ConditionFilter $filter): Comparison|Func|string
    {
        $operator = $filter->operator();
        $field = $this->mapField($filter->field());
        $value = $filter->value()->value();

        if (null === $value) {
            switch ($filter->operator()->value()) {
                case FilterOperator::EQUAL:
                    return $this->queryBuilder->expr()->isNull($field);
                case FilterOperator::NOT_EQUAL:
                    return $this->queryBuilder->expr()->isNotNull($field);
                default:
                    throw new \RuntimeException('Null value cannot be applied to query');
            }
        }

        if (true === $operator->isContains()) {
            /** @phpstan-ignore-next-line */
            $value = '%' . $value . '%';
        } elseif (true === $operator->isStartsWith()) {
            /** @phpstan-ignore-next-line */
            $value = $value . '%';
        } elseif (true === $operator->isEndsWith()) {
            /** @phpstan-ignore-next-line */
            $value = '%' . $value;
        }

        $parameterPosition = $this->parameterCount++;
        $this->queryBuilder->setParameter($parameterPosition, $value);
        $parameterPositionAttribute = "?$parameterPosition";

        switch ($filter->operator()->value()) {
            case FilterOperator::EQUAL:
                return $this->queryBuilder->expr()->eq($field, $parameterPositionAttribute);
            case FilterOperator::NOT_EQUAL:
                return $this->queryBuilder->expr()->neq($field, $parameterPositionAttribute);
            case FilterOperator::GT:
                return $this->queryBuilder->expr()->gt($field, $parameterPositionAttribute);
            case FilterOperator::GTE:
                return $this->queryBuilder->expr()->gte($field, $parameterPositionAttribute);
            case FilterOperator::LT:
                return $this->queryBuilder->expr()->lt($field, $parameterPositionAttribute);
            case FilterOperator::LTE:
                return $this->queryBuilder->expr()->lte($field, $parameterPositionAttribute);
            case FilterOperator::IN:
                return $this->queryBuilder->expr()->in($field, $parameterPositionAttribute);
            case FilterOperator::NOT_IN:
                return $this->queryBuilder->expr()->notIn($field, $parameterPositionAttribute);
            case FilterOperator::STARTS_WITH:
            case FilterOperator::ENDS_WITH:
            case FilterOperator::CONTAINS:
                return $this->queryBuilder->expr()->like($field, $parameterPositionAttribute);
            default:
                throw new \RuntimeException('Unknown criteria operator');
        }
    }

    protected function formatNullableConditionExpression(ConditionFilter $filter): Comparison|Func|Andx|string
    {
        $fields = $this->mapFields($filter->field());

        if (1 === count($fields)) {
            return $this->formatBasicCondition($filter);
        }

        return $this->queryBuilder->expr()->andX(
            ...array_map(
            fn(string $field) => $this->formatBasicCondition(
                new ConditionFilter(new FilterField($field), $filter->operator(), $filter->value()),
            ),
            $fields,
        ),
        );
    }

    private function mapField(FilterField $field): string
    {
        return $this->mapFieldValue($field->value());
    }

    private function mapFields(FilterField $field): array
    {
        return $this->mapFieldValues($field->value());
    }

    private function mapOrderBy(CriteriaOrderBy $field): string
    {
        return $this->mapFieldValue($field->value());
    }

    private function mapFieldValue(string $field): string
    {
        return $this->fields[$field] ?? $field;
    }

    private function mapFieldValues(string $field): array
    {
        if (true === isset($this->fields[$field])) {
            return [$this->fields[$field]];
        }

        $fields = [];
        $auxField = "$field.";

        foreach ($this->fields as $value) {
            if (true === str_starts_with($value, $auxField)) {
                $fields[] = $value;
            }
        }

        return true === empty($fields) ? [$field] : $fields;
    }
}