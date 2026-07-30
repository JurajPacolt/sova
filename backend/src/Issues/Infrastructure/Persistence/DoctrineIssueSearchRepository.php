<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Exception;
use Sova\Issues\Application\Search\CompiledQuery;
use Sova\Issues\Application\Search\CompiledSort;
use Sova\Issues\Application\Search\IssueSearchRepository;
use Sova\Issues\Application\Search\QueryTimedOutException;
use Sova\Issues\Application\Search\SearchCursor;
use Sova\Issues\Application\Search\SearchResultItem;
use Sova\Issues\Application\Search\SearchRow;
use Sova\Issues\Application\Search\SearchScope;
use Sova\Issues\Domain\QueryLanguage\SortDirection;
use Sova\Shared\Infrastructure\Configuration\Settings;

/**
 * Runs a compiled query under the tenant and project predicate.
 *
 * The scope predicate is written here, before the compiled filter is appended,
 * and both are joined with `AND` — so no compiled fragment, however it was
 * produced, can widen the row set beyond the projects the caller may search.
 * The statement runs under a PostgreSQL statement timeout, which is the last
 * line of defence against a query that is cheap to write and expensive to run.
 */
final readonly class DoctrineIssueSearchRepository implements IssueSearchRepository
{
    /** PostgreSQL `query_canceled`, raised when the statement timeout fires. */
    private const string TIMEOUT_SQL_STATE = '57014';

    private int $timeoutMilliseconds;

    public function __construct(
        private Connection $connection,
        Settings $settings,
    ) {
        $timeout = $settings->int('search.statement_timeout_ms', 3000);
        $this->timeoutMilliseconds = $timeout > 0 ? $timeout : 3000;
    }

    /**
     * @return list<SearchRow>
     */
    public function search(
        SearchScope $scope,
        CompiledQuery $compiled,
        ?SearchCursor $cursor,
        int $limit,
    ): array {
        if ($scope->isEmpty()) {
            return [];
        }

        $parameters = $compiled->parameters;
        $types = $compiled->parameterTypes;

        $parameters['scope_tenant'] = $scope->tenantId;
        $types['scope_tenant'] = ParameterType::STRING;
        $parameters['scope_projects'] = $scope->projectIds;
        $types['scope_projects'] = ArrayParameterType::STRING;

        $conditions = [
            'issue.tenant_id = :scope_tenant',
            'issue.project_id IN (:scope_projects)',
        ];

        if ($compiled->filterSql !== '') {
            $conditions[] = '(' . $compiled->filterSql . ')';
        }

        if ($cursor !== null) {
            $keyset = $this->keyset($compiled->sort, $cursor, $parameters, $types);

            if ($keyset !== null) {
                $conditions[] = '(' . $keyset . ')';
            }
        }

        $parameters['scope_limit'] = $limit;
        $types['scope_limit'] = ParameterType::INTEGER;

        $sql = sprintf(
            "%s\nWHERE %s\nORDER BY %s\nLIMIT :scope_limit",
            $this->selectSql($compiled->sort),
            implode("\n    AND ", $conditions),
            $this->orderBySql($compiled->sort),
        );

        return $this->execute($sql, $parameters, $types, $compiled->sort);
    }

    /**
     * @param array<string, list<int>|list<string>|int|string> $parameters
     * @param array<string, ArrayParameterType|ParameterType>   $types
     * @param list<CompiledSort>                                $sort
     *
     * @return list<SearchRow>
     */
    private function execute(
        string $sql,
        array $parameters,
        array $types,
        array $sort,
    ): array {
        $inTransaction = $this->connection->isTransactionActive();

        try {
            $this->connection->executeStatement(sprintf(
                'SET %sstatement_timeout = %d',
                $inTransaction ? 'LOCAL ' : '',
                $this->timeoutMilliseconds,
            ));

            $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        } catch (DriverException $exception) {
            if ($exception->getSQLState() === self::TIMEOUT_SQL_STATE) {
                throw new QueryTimedOutException(
                    'The search exceeded the configured statement timeout.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        } finally {
            if (!$inTransaction) {
                // `SET LOCAL` reverts with the transaction; a session-level set
                // has to be undone explicitly or it would leak into reuse of
                // this connection.
                $this->connection->executeStatement('SET statement_timeout = DEFAULT');
            }
        }

        return array_map(
            fn(array $row): SearchRow => $this->hydrate($row, $sort),
            $rows,
        );
    }

    /**
     * Lexicographic keyset predicate: a row belongs to the next page when its
     * first differing sort value is beyond the cursor's, with `issue.id` as the
     * final tie-breaker. NULLs are compared according to the placement the
     * `ORDER BY` actually used, otherwise a page boundary landing on a NULL
     * would skip or repeat rows.
     *
     * @param list<CompiledSort>                                $sort
     * @param array<string, list<int>|list<string>|int|string> $parameters
     * @param array<string, ArrayParameterType|ParameterType>   $types
     */
    private function keyset(
        array $sort,
        SearchCursor $cursor,
        array &$parameters,
        array &$types,
    ): ?string {
        if (count($cursor->sortValues) !== count($sort)) {
            return null;
        }

        $predicate = 'issue.id > :cursor_id';
        $parameters['cursor_id'] = $cursor->issueId;
        $types['cursor_id'] = ParameterType::STRING;

        for ($index = count($sort) - 1; $index >= 0; $index--) {
            $term = $sort[$index];
            $value = $cursor->sortValues[$index];
            $name = sprintf('cursor_%d', $index);

            if ($value === null) {
                $equal = sprintf('%s IS NULL', $term->expression);
                $beyond = $term->nullsFirst
                    ? sprintf('%s IS NOT NULL', $term->expression)
                    : null;
            } else {
                $parameters[$name] = $value;
                $types[$name] = ParameterType::STRING;

                $operator = $term->direction === SortDirection::Ascending ? '>' : '<';
                $equal = sprintf('%s = :%s', $term->expression, $name);
                $beyond = sprintf('%s %s :%s', $term->expression, $operator, $name);

                if (!$term->nullsFirst) {
                    // NULLs sort after every value, so a NULL is beyond it too.
                    $beyond = sprintf('(%s OR %s IS NULL)', $beyond, $term->expression);
                }
            }

            $predicate = $beyond === null
                ? sprintf('%s AND (%s)', $equal, $predicate)
                : sprintf('%s OR (%s AND (%s))', $beyond, $equal, $predicate);
        }

        return $predicate;
    }

    /**
     * @param list<CompiledSort> $sort
     */
    private function selectSql(array $sort): string
    {
        $projections = '';

        foreach ($sort as $term) {
            $projections .= sprintf(",\n    (%s)::text AS %s", $term->expression, $term->alias);
        }

        return <<<SQL
            SELECT
                issue.id,
                issue.issue_key,
                issue.title,
                issue.priority,
                issue.resolution,
                issue.resolved_at,
                issue.created_at,
                issue.updated_at,
                issue.project_id,
                project.code AS project_code,
                project.name AS project_name,
                issue_type.code AS issue_type_code,
                issue_type.name AS issue_type_name,
                issue_type.hierarchy_level,
                status.code AS status_code,
                status.name AS status_name,
                status.category AS status_category,
                issue.assignee_membership_id,
                assignee_user.display_name AS assignee_display_name,
                issue.assignee_workgroup_id,
                assignee_workgroup.name AS assignee_workgroup_name,
                parent.issue_key AS parent_issue_key,
                EXISTS (
                    SELECT 1
                    FROM issue_links link
                    INNER JOIN issues blocker
                        ON blocker.tenant_id = link.tenant_id
                        AND blocker.id = link.source_issue_id
                    INNER JOIN project_statuses blocker_status
                        ON blocker_status.tenant_id = blocker.tenant_id
                        AND blocker_status.project_id = blocker.project_id
                        AND blocker_status.id = blocker.status_id
                    WHERE link.tenant_id = issue.tenant_id
                        AND link.target_issue_id = issue.id
                        AND link.link_type = 'BLOCKS'
                        AND blocker_status.category <> 'DONE'
                        AND blocker.project_id IN (:scope_projects)
                ) AS blocked{$projections}
            FROM issues issue
            INNER JOIN projects project
                ON project.tenant_id = issue.tenant_id
                AND project.id = issue.project_id
            INNER JOIN project_issue_types issue_type
                ON issue_type.tenant_id = issue.tenant_id
                AND issue_type.project_id = issue.project_id
                AND issue_type.id = issue.issue_type_id
            INNER JOIN project_statuses status
                ON status.tenant_id = issue.tenant_id
                AND status.project_id = issue.project_id
                AND status.id = issue.status_id
            LEFT JOIN tenant_memberships assignee
                ON assignee.tenant_id = issue.tenant_id
                AND assignee.id = issue.assignee_membership_id
            LEFT JOIN users assignee_user
                ON assignee_user.id = assignee.user_id
            LEFT JOIN workgroups assignee_workgroup
                ON assignee_workgroup.tenant_id = issue.tenant_id
                AND assignee_workgroup.id = issue.assignee_workgroup_id
            LEFT JOIN issues parent
                ON parent.tenant_id = issue.tenant_id
                AND parent.project_id = issue.project_id
                AND parent.id = issue.parent_issue_id
            SQL;
    }

    /**
     * @param list<CompiledSort> $sort
     */
    private function orderBySql(array $sort): string
    {
        $terms = [];

        foreach ($sort as $item) {
            $terms[] = sprintf(
                '%s %s NULLS %s',
                $item->expression,
                $item->direction->value,
                $item->nullsFirst ? 'FIRST' : 'LAST',
            );
        }

        // The stable tie-breaker is always present, even though the canonical
        // query text never shows it (spec §4.10).
        $terms[] = 'issue.id ASC';

        return implode(', ', $terms);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<CompiledSort>   $sort
     */
    private function hydrate(array $row, array $sort): SearchRow
    {
        $values = [];

        foreach ($sort as $term) {
            $value = $row[$term->alias] ?? null;
            $values[] = is_string($value) ? $value : null;
        }

        return new SearchRow(
            new SearchResultItem(
                $this->string($row, 'id'),
                $this->string($row, 'issue_key'),
                $this->string($row, 'title'),
                $this->string($row, 'project_id'),
                $this->string($row, 'project_code'),
                $this->string($row, 'project_name'),
                $this->string($row, 'issue_type_code'),
                $this->string($row, 'issue_type_name'),
                (int) $this->string($row, 'hierarchy_level'),
                $this->string($row, 'status_code'),
                $this->string($row, 'status_name'),
                $this->string($row, 'status_category'),
                $this->string($row, 'priority'),
                $this->nullableString($row, 'assignee_membership_id'),
                $this->nullableString($row, 'assignee_display_name'),
                $this->nullableString($row, 'assignee_workgroup_id'),
                $this->nullableString($row, 'assignee_workgroup_name'),
                $this->nullableString($row, 'parent_issue_key'),
                $this->flag($row, 'blocked'),
                $this->nullableString($row, 'resolution'),
                $this->moment($this->nullableString($row, 'resolved_at')),
                $this->moment($this->string($row, 'created_at')) ?? new DateTimeImmutable(),
                $this->moment($this->string($row, 'updated_at')) ?? new DateTimeImmutable(),
            ),
            $values,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function flag(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;

        // PDO hands booleans back as `true`, `'t'` or `'1'` depending on the
        // driver's emulation, so all three are read as the same yes.
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    private function moment(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
