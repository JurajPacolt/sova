<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use Sova\Issues\Domain\QueryLanguage\Ast\BooleanLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\ComparisonPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\EmptyPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\Expression;
use Sova\Issues\Domain\QueryLanguage\Ast\FieldReference;
use Sova\Issues\Domain\QueryLanguage\Ast\FunctionCall;
use Sova\Issues\Domain\QueryLanguage\Ast\IdentifierValue;
use Sova\Issues\Domain\QueryLanguage\Ast\LogicalExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NotExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NumberLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\Ast\SetPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\SortItem;
use Sova\Issues\Domain\QueryLanguage\Ast\StringLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Value;

/**
 * Renders a validated AST back into the one canonical SovaQL text used for the
 * cursor hash, saved queries and audit. Keywords are upper-cased, field names
 * take their catalog spelling, values are normalised, and parentheses are added
 * only where precedence requires them so that `parse(print(parse(x)))` is
 * stable.
 */
final class CanonicalPrinter
{
    private const int PRECEDENCE_OR = 1;

    private const int PRECEDENCE_AND = 2;

    private const int PRECEDENCE_ATOM = 3;

    public function __construct(
        private readonly FieldCatalog $fields,
        private readonly FunctionCatalog $functions,
    ) {}

    public function print(Query $query): string
    {
        $parts = [];

        if ($query->filter !== null) {
            $parts[] = $this->printExpression($query->filter);
        }

        if ($query->sort !== []) {
            $parts[] = 'ORDER BY ' . implode(
                ', ',
                array_map($this->printSortItem(...), $query->sort),
            );
        }

        return implode(' ', $parts);
    }

    private function printExpression(Expression $expression): string
    {
        return match (true) {
            $expression instanceof LogicalExpression => $this->printLogical($expression),
            $expression instanceof NotExpression => $this->printNot($expression),
            $expression instanceof ComparisonPredicate => $this->printComparison($expression),
            $expression instanceof SetPredicate => $this->printSet($expression),
            $expression instanceof EmptyPredicate => $this->printEmpty($expression),
            default => '',
        };
    }

    private function printLogical(LogicalExpression $expression): string
    {
        $operator = $expression->operator === LogicalOperator::And
            ? self::PRECEDENCE_AND
            : self::PRECEDENCE_OR;

        return sprintf(
            '%s %s %s',
            $this->printOperand($expression->left, $operator),
            $expression->operator->value,
            $this->printOperand($expression->right, $operator),
        );
    }

    private function printOperand(Expression $operand, int $parentPrecedence): string
    {
        $text = $this->printExpression($operand);

        return $this->precedence($operand) < $parentPrecedence ? '(' . $text . ')' : $text;
    }

    private function printNot(NotExpression $expression): string
    {
        $operand = $expression->operand;
        $text = $this->printExpression($operand);

        if ($operand instanceof LogicalExpression) {
            $text = '(' . $text . ')';
        }

        return 'NOT ' . $text;
    }

    private function printComparison(ComparisonPredicate $predicate): string
    {
        return sprintf(
            '%s %s %s',
            $this->fieldName($predicate->field),
            $predicate->operator->value,
            $this->printValue($predicate->value),
        );
    }

    private function printSet(SetPredicate $predicate): string
    {
        $operator = $predicate->negated ? 'NOT IN' : 'IN';

        if ($predicate->function !== null) {
            return sprintf(
                '%s %s %s',
                $this->fieldName($predicate->field),
                $operator,
                $this->printValue($predicate->function),
            );
        }

        $values = implode(', ', array_map($this->printValue(...), $predicate->values));

        return sprintf('%s %s (%s)', $this->fieldName($predicate->field), $operator, $values);
    }

    private function printEmpty(EmptyPredicate $predicate): string
    {
        return $this->fieldName($predicate->field)
            . ($predicate->negated ? ' IS NOT EMPTY' : ' IS EMPTY');
    }

    /**
     * The canonical text of one value. Public so the basic-editor projection
     * can reuse the same formatting instead of inventing a second one.
     */
    public function printValue(Value $value): string
    {
        return match (true) {
            $value instanceof StringLiteral => $this->quote($value->value),
            $value instanceof NumberLiteral => $this->number($value),
            $value instanceof BooleanLiteral => $value->value ? 'true' : 'false',
            $value instanceof IdentifierValue => strtoupper($value->name),
            $value instanceof FunctionCall => $this->printFunction($value),
            default => '',
        };
    }

    private function printFunction(FunctionCall $function): string
    {
        $definition = $this->functions->definition($function->name);
        $name = $definition->canonicalName ?? $function->name;
        $arguments = implode(', ', array_map($this->printValue(...), $function->arguments));

        return $name . '(' . $arguments . ')';
    }

    private function printSortItem(SortItem $item): string
    {
        $text = $this->fieldName($item->field) . ' ' . $item->direction->value;

        if ($item->nulls !== null) {
            $text .= ' NULLS ' . $item->nulls->value;
        }

        return $text;
    }

    private function fieldName(FieldReference $field): string
    {
        return $this->fields->definition($field->name)->canonicalName ?? $field->name;
    }

    private function number(NumberLiteral $value): string
    {
        return ltrim($value->raw, '+');
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function precedence(Expression $expression): int
    {
        if ($expression instanceof LogicalExpression) {
            return $expression->operator === LogicalOperator::Or
                ? self::PRECEDENCE_OR
                : self::PRECEDENCE_AND;
        }

        return self::PRECEDENCE_ATOM;
    }
}
