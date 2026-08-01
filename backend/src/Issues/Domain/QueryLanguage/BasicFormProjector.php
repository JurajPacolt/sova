<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use Sova\Issues\Domain\QueryLanguage\Ast\ComparisonPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\EmptyPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\Expression;
use Sova\Issues\Domain\QueryLanguage\Ast\LogicalExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\Ast\SetPredicate;

/**
 * Projects a validated AST onto what the control-based editor can draw.
 *
 * This lives on the server for the same reason validation does: the basic and
 * the text editor must agree about the meaning of a query, and they only can if
 * one parser decides it. A client walking the text itself would be a second
 * grammar, free to drift.
 *
 * Only a conjunction of simple conditions projects. `OR`, `NOT` and grouping
 * carry meaning the basic editor has no way to show, so the whole query is
 * reported as not representable rather than partly drawn — the specification is
 * explicit that it must never be silently simplified.
 */
final readonly class BasicFormProjector
{
    public function __construct(
        private FieldCatalog $fields,
        private CanonicalPrinter $printer,
    ) {}

    public function project(Query $query): BasicForm
    {
        $sort = [];

        foreach ($query->sort as $item) {
            $sort[] = new BasicSort(
                $this->fields->definition($item->field->name)->canonicalName
                    ?? $item->field->name,
                $item->direction->value,
                $item->nulls?->value,
            );
        }

        if ($query->filter === null) {
            return BasicForm::of([], $sort);
        }

        $conditions = [];

        return $this->flatten($query->filter, $conditions)
            ? BasicForm::of($conditions, $sort)
            : BasicForm::tooComplex($sort);
    }

    /**
     * @param list<BasicCondition> $conditions
     */
    private function flatten(Expression $expression, array &$conditions): bool
    {
        if ($expression instanceof LogicalExpression) {
            return $expression->operator === LogicalOperator::And
                && $this->flatten($expression->left, $conditions)
                && $this->flatten($expression->right, $conditions);
        }

        $condition = $this->condition($expression);

        if ($condition === null) {
            return false;
        }

        $conditions[] = $condition;

        return true;
    }

    private function condition(Expression $expression): ?BasicCondition
    {
        if ($expression instanceof ComparisonPredicate) {
            return new BasicCondition(
                $this->fieldName($expression->field->name),
                $expression->operator->value,
                [$this->printer->printValue($expression->value)],
            );
        }

        if ($expression instanceof SetPredicate) {
            $values = $expression->function === null
                ? array_map($this->printer->printValue(...), $expression->values)
                : [$this->printer->printValue($expression->function)];

            return new BasicCondition(
                $this->fieldName($expression->field->name),
                $expression->negated ? 'NOT IN' : 'IN',
                $values,
            );
        }

        if ($expression instanceof EmptyPredicate) {
            return new BasicCondition(
                $this->fieldName($expression->field->name),
                $expression->negated ? 'IS NOT EMPTY' : 'IS EMPTY',
                [],
            );
        }

        // A `NOT` expression, or anything else, has no basic-editor shape.
        return null;
    }

    private function fieldName(string $name): string
    {
        return $this->fields->definition($name)->canonicalName ?? $name;
    }
}
