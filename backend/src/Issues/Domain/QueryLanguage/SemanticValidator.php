<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

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
use Sova\Issues\Domain\QueryLanguage\Ast\StringLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Value;

/**
 * Validates a parsed query against the field and function catalogs and the
 * complexity limits. It never resolves references against the database — the
 * existence and accessibility of a concrete project, status, user or group is
 * decided later, inside the caller's authorised scope. Errors are collected
 * rather than thrown so the editor can highlight every problem at once.
 */
final class SemanticValidator
{
    private const array STATUS_CATEGORIES = ['TO_DO', 'IN_PROGRESS', 'DONE'];

    private const array PRIORITIES = ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'];

    public function __construct(
        private readonly FieldCatalog $fields,
        private readonly FunctionCatalog $functions,
        private readonly QueryLimits $limits,
    ) {}

    /**
     * @return list<QueryError>
     */
    public function validate(Query $query): array
    {
        $errors = [];

        if ($query->filter !== null) {
            if ($this->countNodes($query->filter) > $this->limits->maxAstNodes) {
                $errors[] = new QueryError(
                    QueryErrorCode::TooComplex,
                    $query->filter->start(),
                    $query->filter->end(),
                    ['limit' => $this->limits->maxAstNodes],
                );
            }

            $this->validateExpression($query->filter, $errors);
        }

        $this->validateSort($query, $errors);

        return $errors;
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateExpression(Expression $expression, array &$errors): void
    {
        match (true) {
            $expression instanceof LogicalExpression => $this->validateLogical($expression, $errors),
            $expression instanceof NotExpression => $this->validateExpression($expression->operand, $errors),
            $expression instanceof ComparisonPredicate => $this->validateComparison($expression, $errors),
            $expression instanceof SetPredicate => $this->validateSet($expression, $errors),
            $expression instanceof EmptyPredicate => $this->validateEmpty($expression, $errors),
            default => null,
        };
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateLogical(LogicalExpression $expression, array &$errors): void
    {
        $this->validateExpression($expression->left, $errors);
        $this->validateExpression($expression->right, $errors);
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateComparison(ComparisonPredicate $predicate, array &$errors): void
    {
        $field = $this->resolveField($predicate->field, $errors);

        if ($field === null) {
            return;
        }

        if (!$field->allowsComparison($predicate->operator)) {
            $errors[] = new QueryError(
                QueryErrorCode::OperatorNotAllowed,
                $predicate->operatorStart,
                $predicate->operatorEnd,
                ['field' => $field->canonicalName, 'operator' => $predicate->operator->value],
            );

            return;
        }

        $this->validateValue($field, $predicate->value, $errors);
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateSet(SetPredicate $predicate, array &$errors): void
    {
        $field = $this->resolveField($predicate->field, $errors);

        if ($field === null) {
            return;
        }

        if (!$field->allowsSet) {
            $errors[] = new QueryError(
                QueryErrorCode::OperatorNotAllowed,
                $predicate->operatorStart,
                $predicate->operatorEnd,
                ['field' => $field->canonicalName, 'operator' => $predicate->negated ? 'NOT IN' : 'IN'],
            );

            return;
        }

        if ($predicate->function !== null) {
            $this->validateSetFunction($field, $predicate->function, $errors);

            return;
        }

        if (count($predicate->values) > $this->limits->maxInValues) {
            $errors[] = new QueryError(
                QueryErrorCode::TooComplex,
                $predicate->start(),
                $predicate->end(),
                ['limit' => $this->limits->maxInValues],
            );
        }

        foreach ($predicate->values as $value) {
            $this->validateValue($field, $value, $errors);
        }
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateSetFunction(
        FieldDefinition $field,
        FunctionCall $function,
        array &$errors,
    ): void {
        $returnType = $this->validateFunction($function, $errors);

        if ($returnType === null) {
            return;
        }

        $compatible = $field->type === FieldType::User
            && $returnType === FunctionReturnType::UserSet;

        if (!$compatible) {
            $errors[] = new QueryError(
                QueryErrorCode::ValueInvalid,
                $function->start(),
                $function->end(),
                ['field' => $field->canonicalName],
            );
        }
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateEmpty(EmptyPredicate $predicate, array &$errors): void
    {
        $field = $this->resolveField($predicate->field, $errors);

        if ($field === null) {
            return;
        }

        if (!$field->allowsEmpty) {
            $errors[] = new QueryError(
                QueryErrorCode::OperatorNotAllowed,
                $predicate->operatorStart,
                $predicate->end(),
                ['field' => $field->canonicalName, 'operator' => $predicate->negated ? 'IS NOT EMPTY' : 'IS EMPTY'],
            );
        }
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateValue(FieldDefinition $field, Value $value, array &$errors): void
    {
        $valid = match ($field->type) {
            FieldType::IssueKey,
            FieldType::ProjectCode,
            FieldType::IssueTypeCode,
            FieldType::StatusCode,
            FieldType::Label => $value instanceof IdentifierValue || $value instanceof StringLiteral,
            FieldType::Integer => $value instanceof NumberLiteral && $value->isInteger(),
            FieldType::StatusCategory => $this->isEnumMember($value, self::STATUS_CATEGORIES),
            FieldType::Priority => $this->isEnumMember($value, self::PRIORITIES),
            FieldType::Title,
            FieldType::Fulltext => $value instanceof StringLiteral,
            FieldType::User => $this->isFunctionReturning($value, FunctionReturnType::User, $errors),
            FieldType::Workgroup => $this->isFunctionReturning($value, FunctionReturnType::Workgroup, $errors),
            FieldType::Date => $this->isTemporalValue($value, true, $errors),
            FieldType::DateTime,
            FieldType::Duration => $this->isTemporalValue($value, false, $errors),
        };

        if (!$valid) {
            $errors[] = new QueryError(
                QueryErrorCode::ValueInvalid,
                $value->start(),
                $value->end(),
                ['field' => $field->canonicalName],
            );
        }
    }

    /**
     * @param list<string> $members
     */
    private function isEnumMember(Value $value, array $members): bool
    {
        return $value instanceof IdentifierValue
            && in_array(strtoupper($value->name), $members, true);
    }

    /**
     * Validates a function value and reports its own errors, returning true only
     * when it is a well-formed call producing the wanted scalar type.
     *
     * @param list<QueryError> $errors
     */
    private function isFunctionReturning(
        Value $value,
        FunctionReturnType $wanted,
        array &$errors,
    ): bool {
        if (!$value instanceof FunctionCall) {
            return false;
        }

        $returnType = $this->validateFunction($value, $errors);

        // A malformed call already produced an error; suppress the extra
        // "value invalid" so the editor shows the precise cause once.
        return $returnType === null || $returnType === $wanted;
    }

    /**
     * @param list<QueryError> $errors
     */
    private function isTemporalValue(Value $value, bool $dateOnly, array &$errors): bool
    {
        if ($value instanceof StringLiteral) {
            return $this->isIsoTimestamp($value->value, $dateOnly);
        }

        if ($value instanceof FunctionCall) {
            $returnType = $this->validateFunction($value, $errors);

            return $returnType === null || $returnType === FunctionReturnType::DateTime;
        }

        return false;
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateFunction(FunctionCall $function, array &$errors): ?FunctionReturnType
    {
        $definition = $this->functions->definition($function->name);

        if ($definition === null) {
            $errors[] = new QueryError(
                QueryErrorCode::FunctionUnknown,
                $function->start(),
                $function->end(),
                ['function' => $function->name],
            );

            return null;
        }

        $count = count($function->arguments);

        if ($count < $definition->minArguments || $count > $definition->maxArguments) {
            $errors[] = new QueryError(
                QueryErrorCode::FunctionArgumentInvalid,
                $function->start(),
                $function->end(),
                ['function' => $definition->canonicalName],
            );

            return $definition->returnType;
        }

        foreach ($function->arguments as $argument) {
            $this->validateFunctionArgument($definition, $argument, $errors);
        }

        return $definition->returnType;
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateFunctionArgument(
        FunctionDefinition $definition,
        Value $argument,
        array &$errors,
    ): void {
        $valid = $argument instanceof StringLiteral
            && match ($definition->argumentKind) {
                FunctionArgumentKind::Text => trim($argument->value) !== '',
                FunctionArgumentKind::Offset => FunctionCatalog::isValidOffset($argument->value),
            };

        if (!$valid) {
            $errors[] = new QueryError(
                QueryErrorCode::FunctionArgumentInvalid,
                $argument->start(),
                $argument->end(),
                ['function' => $definition->canonicalName],
            );
        }
    }

    /**
     * @param list<QueryError> $errors
     */
    private function validateSort(Query $query, array &$errors): void
    {
        if (count($query->sort) > $this->limits->maxSortFields) {
            $overflow = $query->sort[$this->limits->maxSortFields]->field;
            $errors[] = new QueryError(
                QueryErrorCode::TooComplex,
                $overflow->start(),
                $overflow->end(),
                ['limit' => $this->limits->maxSortFields],
            );
        }

        foreach ($query->sort as $item) {
            $field = $this->fields->definition($item->field->name);

            if ($field === null) {
                $errors[] = $this->fieldError(QueryErrorCode::FieldUnknown, $item->field);

                continue;
            }

            if (!$field->supported) {
                $errors[] = $this->fieldError(QueryErrorCode::FieldNotSupported, $item->field);

                continue;
            }

            if (!$field->sortable) {
                $errors[] = new QueryError(
                    QueryErrorCode::OperatorNotAllowed,
                    $item->field->start(),
                    $item->field->end(),
                    ['field' => $field->canonicalName, 'operator' => 'ORDER BY'],
                );
            }
        }
    }

    /**
     * @param list<QueryError> $errors
     */
    private function resolveField(FieldReference $field, array &$errors): ?FieldDefinition
    {
        $definition = $this->fields->definition($field->name);

        if ($definition === null) {
            $errors[] = $this->fieldError(QueryErrorCode::FieldUnknown, $field);

            return null;
        }

        if (!$definition->supported) {
            $errors[] = $this->fieldError(QueryErrorCode::FieldNotSupported, $field);

            return null;
        }

        return $definition;
    }

    private function fieldError(QueryErrorCode $code, FieldReference $field): QueryError
    {
        return new QueryError($code, $field->start(), $field->end(), ['field' => $field->name]);
    }

    private function countNodes(Expression $expression): int
    {
        return match (true) {
            $expression instanceof LogicalExpression => 1
                + $this->countNodes($expression->left)
                + $this->countNodes($expression->right),
            $expression instanceof NotExpression => 1 + $this->countNodes($expression->operand),
            $expression instanceof ComparisonPredicate => 1 + $this->countValue($expression->value),
            $expression instanceof SetPredicate => $this->countSet($expression),
            default => 1,
        };
    }

    private function countSet(SetPredicate $predicate): int
    {
        $total = 1;

        if ($predicate->function !== null) {
            $total += $this->countValue($predicate->function);
        }

        foreach ($predicate->values as $value) {
            $total += $this->countValue($value);
        }

        return $total;
    }

    private function countValue(Value $value): int
    {
        if (!$value instanceof FunctionCall) {
            return 1;
        }

        $total = 1;

        foreach ($value->arguments as $argument) {
            $total += $this->countValue($argument);
        }

        return $total;
    }

    private function isIsoTimestamp(string $value, bool $dateOnly): bool
    {
        $pattern = $dateOnly
            ? '/^(\d{4})-(\d{2})-(\d{2})$/'
            : '/^(\d{4})-(\d{2})-(\d{2})([T ](\d{2}):(\d{2})(:(\d{2})(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?)?$/';

        if (preg_match($pattern, $value, $matches) !== 1) {
            return false;
        }

        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return false;
        }

        // Hours and minutes are the same optional group, so either both matched
        // or the timestamp carried no time part at all.
        if ($dateOnly || !isset($matches[5], $matches[6]) || $matches[5] === '') {
            return true;
        }

        return (int) $matches[5] < 24 && (int) $matches[6] < 60
            && (($matches[8] ?? '') === '' || (int) $matches[8] < 60);
    }
}
