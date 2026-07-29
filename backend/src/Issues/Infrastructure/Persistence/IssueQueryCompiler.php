<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Sova\Issues\Application\Search\CompiledQuery;
use Sova\Issues\Application\Search\CompiledSort;
use Sova\Issues\Application\Search\QueryCompiler;
use Sova\Issues\Application\Search\ResolvedReferences;
use Sova\Issues\Domain\QueryLanguage\Ast\ComparisonPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\EmptyPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\Expression;
use Sova\Issues\Domain\QueryLanguage\Ast\FunctionCall;
use Sova\Issues\Domain\QueryLanguage\Ast\IdentifierValue;
use Sova\Issues\Domain\QueryLanguage\Ast\LogicalExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NotExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NumberLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\Ast\SetPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\StringLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Value;
use Sova\Issues\Domain\QueryLanguage\ComparisonOperator;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\FieldDefinition;
use Sova\Issues\Domain\QueryLanguage\FieldType;
use Sova\Issues\Domain\QueryLanguage\LogicalOperator;
use Sova\Issues\Domain\QueryLanguage\QueryErrorCode;
use Sova\Issues\Domain\QueryLanguage\SortDirection;
use Sova\Issues\Domain\QueryLanguage\SortNulls;
use Sova\Issues\Domain\QueryLanguage\TemporalEvaluator;

/**
 * Translates a **validated** AST into a parameterised SQL filter.
 *
 * Three rules make this safe and none of them may be relaxed: a column name is
 * only ever taken from {@see self::COLUMNS} (never assembled from a user token),
 * a value is only ever a bound parameter (never concatenated into SQL), and the
 * translation is structural (never textual substitution). The tenant and project
 * predicate is not built here at all — the repository always prepends it, so no
 * compiled fragment can drop it.
 */
final readonly class IssueQueryCompiler implements QueryCompiler
{
    /**
     * A UUID that no row can carry, used where a reference is legitimately
     * unmatchable — for example `assignee = currentUser()` asked by a superadmin
     * who is not a member. `= nil` then matches nothing and `<> nil` matches
     * every assigned issue, which is exactly the intended meaning.
     */
    private const string UNMATCHABLE_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * The whitelist. Every filterable field maps to one fixed column expression.
     *
     * @var array<string, string>
     */
    private const array COLUMNS = [
        'key' => 'issue.issue_key',
        'project' => 'issue.project_id',
        'type' => 'issue.issue_type_id',
        'hierarchyLevel' => 'issue_type.hierarchy_level',
        'status' => 'issue.status_id',
        'statusCategory' => 'status.category',
        'priority' => 'issue.priority',
        'title' => 'issue.title',
        'reporter' => 'issue.reporter_membership_id',
        'assignee' => 'issue.assignee_membership_id',
        'group' => 'issue.assignee_workgroup_id',
        'parent' => 'issue.parent_issue_id',
        // Not a column: watchers live in their own table, so the predicate is
        // built by self::watcher() instead of comparing this placeholder.
        'watcher' => 'issue.id',
        'created' => 'issue.created_at',
        'updated' => 'issue.updated_at',
        'resolved' => 'issue.resolved_at',
    ];

    /**
     * Sorting expressions. `priority` and `statusCategory` sort by meaning, not
     * alphabetically — `ORDER BY priority DESC` must yield CRITICAL first, not
     * NORMAL. The `CASE` bodies are constants, so no user input reaches them.
     *
     * @var array<string, array{string, bool}>
     */
    private const array SORT_EXPRESSIONS = [
        'key' => ['issue.number', true],
        'project' => ['project.code', false],
        'type' => ['issue_type.code', false],
        'hierarchyLevel' => ['issue_type.hierarchy_level', true],
        'status' => ['status.code', false],
        'statusCategory' => [
            "CASE status.category"
                . " WHEN 'TO_DO' THEN 1 WHEN 'IN_PROGRESS' THEN 2 WHEN 'DONE' THEN 3 ELSE 0 END",
            true,
        ],
        'priority' => [
            "CASE issue.priority"
                . " WHEN 'LOW' THEN 1 WHEN 'NORMAL' THEN 2 WHEN 'HIGH' THEN 3"
                . " WHEN 'CRITICAL' THEN 4 ELSE 0 END",
            true,
        ],
        'title' => ['LOWER(issue.title)', false],
        'created' => ['issue.created_at', false],
        'updated' => ['issue.updated_at', false],
        'resolved' => ['issue.resolved_at', false],
    ];

    public function __construct(private FieldCatalog $fields) {}

    public function compile(
        Query $query,
        ResolvedReferences $references,
        ?TemporalEvaluator $clock = null,
    ): CompiledQuery {
        $state = new CompilerState(
            $references,
            $clock ?? new TemporalEvaluator(new DateTimeZone('UTC')),
        );

        $filter = $query->filter === null
            ? ''
            : $this->expression($query->filter, $state);

        return new CompiledQuery(
            $filter,
            $state->parameters,
            $state->parameterTypes,
            $this->sort($query),
            $state->errors,
        );
    }

    private function expression(Expression $expression, CompilerState $state): string
    {
        return match (true) {
            $expression instanceof LogicalExpression => sprintf(
                '(%s %s %s)',
                $this->expression($expression->left, $state),
                $expression->operator === LogicalOperator::And ? 'AND' : 'OR',
                $this->expression($expression->right, $state),
            ),
            $expression instanceof NotExpression => sprintf(
                'NOT (%s)',
                $this->expression($expression->operand, $state),
            ),
            $expression instanceof ComparisonPredicate => $this->comparison($expression, $state),
            $expression instanceof SetPredicate => $this->set($expression, $state),
            $expression instanceof EmptyPredicate => $this->empty($expression),
            default => 'TRUE',
        };
    }

    private function comparison(ComparisonPredicate $predicate, CompilerState $state): string
    {
        $field = $this->fields->definition($predicate->field->name);

        if ($field === null) {
            return 'TRUE';
        }

        $negated = in_array(
            $predicate->operator,
            [ComparisonOperator::NotEquals, ComparisonOperator::NotMatches],
            true,
        );

        return match ($field->type) {
            FieldType::Title => $this->title($predicate, $state),
            FieldType::Fulltext => $this->fulltext($predicate, $state),
            FieldType::ProjectCode,
            FieldType::IssueTypeCode,
            FieldType::StatusCode,
            FieldType::IssueKey,
            FieldType::User,
            FieldType::Workgroup => $this->membership(
                $field,
                [$predicate->value],
                $negated,
                $state,
            ),
            FieldType::DateTime,
            FieldType::Date => $this->temporal($field, $predicate, $state),
            default => $this->scalar($field, $predicate, $state),
        };
    }

    /**
     * Code, key and identity fields all reduce to "the column is (not) one of
     * these resolved identifiers", which keeps `=`, `!=`, `IN` and `NOT IN` on a
     * single, auditable code path.
     *
     * @param list<Value> $values
     */
    private function membership(
        FieldDefinition $field,
        array $values,
        bool $negated,
        CompilerState $state,
    ): string {
        $identifiers = [];
        $failed = false;

        foreach ($values as $value) {
            $resolved = $this->identifiers($field, $value, $state);

            if ($resolved === null) {
                $failed = true;

                continue;
            }

            foreach ($resolved as $identifier) {
                $identifiers[$identifier] = true;
            }
        }

        if ($failed) {
            return 'FALSE';
        }

        $list = array_keys($identifiers);

        if ($list === []) {
            $list = [self::UNMATCHABLE_ID];
        }

        $placeholder = $state->bindList($list, ArrayParameterType::STRING);

        if ($field->canonicalName === 'watcher') {
            return $this->watcher($placeholder, $negated);
        }

        return sprintf(
            '%s %sIN (:%s)',
            self::COLUMNS[$field->canonicalName],
            $negated ? 'NOT ' : '',
            $placeholder,
        );
    }

    /**
     * `watcher` is a relation rather than a column, so it compiles to an
     * existence test. Negation wraps the whole test: "not watched by X" has to
     * include issues nobody watches, which `NOT IN` over a join would drop.
     */
    private function watcher(string $placeholder, bool $negated): string
    {
        return sprintf(
            '%sEXISTS (SELECT 1 FROM issue_watchers watcher'
                . ' WHERE watcher.tenant_id = issue.tenant_id'
                . ' AND watcher.issue_id = issue.id'
                . ' AND watcher.watching'
                . ' AND watcher.membership_id IN (:%s))',
            $negated ? 'NOT ' : '',
            $placeholder,
        );
    }

    /**
     * Resolves one value node into the identifiers it stands for, or null when
     * it cannot be resolved inside the scope — in which case the error has
     * already been recorded.
     *
     * @return list<string>|null
     */
    private function identifiers(
        FieldDefinition $field,
        Value $value,
        CompilerState $state,
    ): ?array {
        if ($value instanceof FunctionCall) {
            return $this->functionIdentifiers($value, $state);
        }

        $literal = match (true) {
            $value instanceof IdentifierValue => strtoupper($value->name),
            $value instanceof StringLiteral => strtoupper($value->value),
            default => null,
        };

        if ($literal === null) {
            $state->fail(QueryErrorCode::ValueInvalid, $value);

            return null;
        }

        $references = $state->references;

        $resolved = match ($field->type) {
            FieldType::ProjectCode => isset($references->projectIdByCode[$literal])
                ? [$references->projectIdByCode[$literal]]
                : null,
            FieldType::IssueTypeCode => $references->issueTypeIdsByCode[$literal] ?? null,
            FieldType::StatusCode => $references->statusIdsByCode[$literal] ?? null,
            FieldType::IssueKey => $field->canonicalName === 'key'
                ? [$literal]
                : (isset($references->issueIdByKey[$literal])
                    ? [$references->issueIdByKey[$literal]]
                    : null),
            default => null,
        };

        if ($resolved === null || $resolved === []) {
            $state->fail(QueryErrorCode::ValueNotAvailable, $value);

            return null;
        }

        return $resolved;
    }

    /**
     * @return list<string>|null
     */
    private function functionIdentifiers(FunctionCall $function, CompilerState $state): ?array
    {
        $references = $state->references;
        $name = strtolower($function->name);

        if ($name === 'currentuser') {
            return [$references->currentMembershipId ?? self::UNMATCHABLE_ID];
        }

        $argument = $function->arguments[0] ?? null;
        $reference = $argument instanceof StringLiteral ? $argument->value : null;

        if ($reference === null) {
            $state->fail(QueryErrorCode::FunctionArgumentInvalid, $function);

            return null;
        }

        if ($references->isAmbiguous($reference)) {
            $state->fail(QueryErrorCode::ValueAmbiguous, $function);

            return null;
        }

        $resolved = match ($name) {
            'user' => isset($references->membershipIdByReference[$reference])
                ? [$references->membershipIdByReference[$reference]]
                : null,
            'group' => isset($references->workgroupIdByReference[$reference])
                ? [$references->workgroupIdByReference[$reference]]
                : null,
            'membersof' => $references->membershipIdsByGroupReference[$reference] ?? null,
            default => null,
        };

        if ($resolved === null) {
            $state->fail(QueryErrorCode::ValueNotAvailable, $function);

            return null;
        }

        // A group with no active members is a legitimate answer, not an error;
        // it simply matches nothing.
        return $resolved === [] ? [self::UNMATCHABLE_ID] : $resolved;
    }

    private function set(SetPredicate $predicate, CompilerState $state): string
    {
        $field = $this->fields->definition($predicate->field->name);

        if ($field === null) {
            return 'TRUE';
        }

        if ($field->type === FieldType::Integer) {
            return $this->integerSet($field, $predicate, $state);
        }

        if ($field->type === FieldType::StatusCategory || $field->type === FieldType::Priority) {
            return $this->enumSet($field, $predicate, $state);
        }

        $values = $predicate->function === null
            ? $predicate->values
            : [$predicate->function];

        return $this->membership($field, $values, $predicate->negated, $state);
    }

    private function integerSet(
        FieldDefinition $field,
        SetPredicate $predicate,
        CompilerState $state,
    ): string {
        $numbers = [];

        foreach ($predicate->values as $value) {
            if (!$value instanceof NumberLiteral || !$value->isInteger()) {
                $state->fail(QueryErrorCode::ValueInvalid, $value);

                return 'FALSE';
            }

            $numbers[] = $value->toInt();
        }

        return sprintf(
            '%s %sIN (:%s)',
            self::COLUMNS[$field->canonicalName],
            $predicate->negated ? 'NOT ' : '',
            $state->bindList($numbers, ArrayParameterType::INTEGER),
        );
    }

    private function enumSet(
        FieldDefinition $field,
        SetPredicate $predicate,
        CompilerState $state,
    ): string {
        $members = [];

        foreach ($predicate->values as $value) {
            if (!$value instanceof IdentifierValue) {
                $state->fail(QueryErrorCode::ValueInvalid, $value);

                return 'FALSE';
            }

            $members[] = strtoupper($value->name);
        }

        return sprintf(
            '%s %sIN (:%s)',
            self::COLUMNS[$field->canonicalName],
            $predicate->negated ? 'NOT ' : '',
            $state->bindList($members, ArrayParameterType::STRING),
        );
    }

    private function scalar(
        FieldDefinition $field,
        ComparisonPredicate $predicate,
        CompilerState $state,
    ): string {
        $value = $predicate->value;

        if ($field->type === FieldType::Integer) {
            if (!$value instanceof NumberLiteral || !$value->isInteger()) {
                $state->fail(QueryErrorCode::ValueInvalid, $value);

                return 'FALSE';
            }

            return sprintf(
                '%s %s :%s',
                self::COLUMNS[$field->canonicalName],
                $predicate->operator->value,
                $state->bind($value->toInt(), ParameterType::INTEGER),
            );
        }

        if (!$value instanceof IdentifierValue) {
            $state->fail(QueryErrorCode::ValueInvalid, $value);

            return 'FALSE';
        }

        return sprintf(
            '%s %s :%s',
            self::COLUMNS[$field->canonicalName],
            $predicate->operator->value,
            $state->bind(strtoupper($value->name), ParameterType::STRING),
        );
    }

    /**
     * `~` on the title is a safe, index-friendly contains search — not a regular
     * expression. LIKE metacharacters inside the value are escaped so they are
     * matched literally instead of widening the pattern.
     */
    private function title(ComparisonPredicate $predicate, CompilerState $state): string
    {
        $value = $predicate->value;

        if (!$value instanceof StringLiteral) {
            $state->fail(QueryErrorCode::ValueInvalid, $value);

            return 'FALSE';
        }

        if (
            $predicate->operator === ComparisonOperator::Equals
            || $predicate->operator === ComparisonOperator::NotEquals
        ) {
            return sprintf(
                'issue.title %s :%s',
                $predicate->operator->value,
                $state->bind($value->value, ParameterType::STRING),
            );
        }

        $pattern = '%' . str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value->value,
        ) . '%';

        return sprintf(
            "issue.title %sILIKE :%s ESCAPE '\\'",
            $predicate->operator === ComparisonOperator::NotMatches ? 'NOT ' : '',
            $state->bind($pattern, ParameterType::STRING),
        );
    }

    /**
     * Fulltext uses PostgreSQL's parser, never `LIKE` and never a regular
     * expression. `websearch_to_tsquery` accepts the quoted-phrase syntax the
     * spec shows and cannot be driven into an unbounded wildcard scan. The
     * `simple` configuration is the documented safe default until measurement
     * justifies a language-aware one.
     */
    private function fulltext(ComparisonPredicate $predicate, CompilerState $state): string
    {
        $value = $predicate->value;

        if (!$value instanceof StringLiteral) {
            $state->fail(QueryErrorCode::ValueInvalid, $value);

            return 'FALSE';
        }

        return sprintf(
            "%sto_tsvector('simple', issue.title || ' ' || issue.description)"
                . " @@ websearch_to_tsquery('simple', :%s)",
            $predicate->operator === ComparisonOperator::NotMatches ? 'NOT ' : '',
            $state->bind($value->value, ParameterType::STRING),
        );
    }

    private function temporal(
        FieldDefinition $field,
        ComparisonPredicate $predicate,
        CompilerState $state,
    ): string {
        $moment = $this->moment($predicate->value, $state);

        if ($moment === null) {
            return 'FALSE';
        }

        return sprintf(
            '%s %s :%s',
            self::COLUMNS[$field->canonicalName],
            $predicate->operator->value,
            $state->bind($moment->format('Y-m-d H:i:s.u P'), ParameterType::STRING),
        );
    }

    private function moment(Value $value, CompilerState $state): ?DateTimeImmutable
    {
        if ($value instanceof FunctionCall) {
            return $state->clock->evaluate($value);
        }

        if (!$value instanceof StringLiteral) {
            $state->fail(QueryErrorCode::ValueInvalid, $value);

            return null;
        }

        $moment = $this->parseIso($value->value);

        if ($moment === null) {
            $state->fail(QueryErrorCode::ValueInvalid, $value);
        }

        return $moment;
    }

    private function parseIso(string $value): ?DateTimeImmutable
    {
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $moment = DateTimeImmutable::createFromFormat(
                $format,
                $value,
                new DateTimeZone('UTC'),
            );

            if ($moment !== false) {
                return $format === 'Y-m-d' ? $moment->setTime(0, 0, 0, 0) : $moment;
            }
        }

        return null;
    }

    private function empty(EmptyPredicate $predicate): string
    {
        $field = $this->fields->definition($predicate->field->name);

        if ($field === null) {
            return 'TRUE';
        }

        return sprintf(
            '%s IS %sNULL',
            self::COLUMNS[$field->canonicalName],
            $predicate->negated ? 'NOT ' : '',
        );
    }

    /**
     * @return list<CompiledSort>
     */
    private function sort(Query $query): array
    {
        $sorted = [];
        $index = 0;

        foreach ($query->sort as $item) {
            $field = $this->fields->definition($item->field->name);

            if ($field === null || !isset(self::SORT_EXPRESSIONS[$field->canonicalName])) {
                continue;
            }

            [$expression, $numeric] = self::SORT_EXPRESSIONS[$field->canonicalName];

            $sorted[] = new CompiledSort(
                $field->canonicalName,
                $expression,
                sprintf('sort_%d', $index++),
                $item->direction,
                $this->nullsFirst($item->nulls, $item->direction),
                $numeric,
            );
        }

        return $sorted;
    }

    /**
     * PostgreSQL's default is NULLS LAST for ASC and NULLS FIRST for DESC; an
     * explicit `NULLS` clause overrides it. The cursor must know which one won,
     * or a page boundary that lands on a null would skip or repeat rows.
     */
    private function nullsFirst(?SortNulls $nulls, SortDirection $direction): bool
    {
        if ($nulls !== null) {
            return $nulls === SortNulls::First;
        }

        return $direction === SortDirection::Descending;
    }
}
