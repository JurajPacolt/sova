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
use Sova\Issues\Application\Search\AggregationBucket;
use Sova\Issues\Application\Search\AggregationCell;
use Sova\Issues\Application\Search\AggregationField;
use Sova\Issues\Application\Search\CompiledQuery;
use Sova\Issues\Application\Search\IssueAggregationRepository;
use Sova\Issues\Application\Search\QueryTimedOutException;
use Sova\Issues\Application\Search\SearchScope;
use Sova\Issues\Application\Search\TimeSeriesBucket;
use Sova\Issues\Application\Search\TimeSeriesEvent;
use Sova\Issues\Application\Search\TimeSeriesPoint;
use Sova\Shared\Infrastructure\Configuration\Settings;

/**
 * Aggregates inside the caller's authorised scope.
 *
 * The tenant and project predicate is written here and joined to the compiled
 * filter with `AND` **before** anything is grouped. Counting first and filtering
 * afterwards would already have leaked the existence of rows the caller may not
 * see — a total is as much of a disclosure as a row.
 *
 * Grouping expressions come only from {@see self::GROUPING}; nothing is ever
 * assembled from a stored string.
 */
final readonly class DoctrineIssueAggregationRepository implements IssueAggregationRepository
{
    /** PostgreSQL `query_canceled`, raised when the statement timeout fires. */
    private const string TIMEOUT_SQL_STATE = '57014';

    /**
     * The whitelist: each field maps to a fixed key expression and a fixed
     * label expression.
     *
     * @var array<string, array{string, string}>
     */
    private const array GROUPING = [
        'project' => ['issue.project_id::text', 'project.code'],
        'type' => ['issue_type.id::text', 'issue_type.name'],
        'status' => ['status.id::text', 'status.name'],
        'statusCategory' => ['status.category', 'status.category'],
        'priority' => ['issue.priority', 'issue.priority'],
        'assignee' => ['issue.assignee_membership_id::text', 'assignee_user.display_name'],
        'group' => ['issue.assignee_workgroup_id::text', 'assignee_workgroup.name'],
    ];

    /** Each event maps to one fixed column. */
    private const array EVENT_COLUMNS = [
        'CREATED' => 'issue.created_at',
        'RESOLVED' => 'issue.resolved_at',
    ];

    private const array BUCKET_UNITS = [
        'DAY' => 'day',
        'WEEK' => 'week',
        'MONTH' => 'month',
    ];

    private int $timeoutMilliseconds;

    public function __construct(
        private Connection $connection,
        Settings $settings,
    ) {
        $timeout = $settings->int('search.statement_timeout_ms', 3000);
        $this->timeoutMilliseconds = $timeout > 0 ? $timeout : 3000;
    }

    public function count(SearchScope $scope, CompiledQuery $compiled): int
    {
        if ($scope->isEmpty()) {
            return 0;
        }

        $parameters = $compiled->parameters;
        $types = $compiled->parameterTypes;

        $rows = $this->execute(
            sprintf(
                "SELECT COUNT(*) AS total\n%s\nWHERE %s",
                $this->fromSql(),
                $this->predicate($scope, $compiled, $parameters, $types),
            ),
            $parameters,
            $types,
        );

        $total = $rows[0]['total'] ?? null;

        return is_numeric($total) ? (int) $total : 0;
    }

    public function breakdown(
        SearchScope $scope,
        CompiledQuery $compiled,
        AggregationField $field,
        int $limit,
        bool $includeEmpty,
        bool $sortByLabel,
    ): array {
        if ($scope->isEmpty()) {
            return [];
        }

        [$keyExpression, $labelExpression] = self::GROUPING[$field->value];
        $parameters = $compiled->parameters;
        $types = $compiled->parameterTypes;
        $conditions = $this->predicate($scope, $compiled, $parameters, $types);

        if (!$includeEmpty) {
            $conditions .= sprintf("\n    AND %s IS NOT NULL", $keyExpression);
        }

        $parameters['aggregation_limit'] = $limit;
        $types['aggregation_limit'] = ParameterType::INTEGER;

        $rows = $this->execute(
            sprintf(
                "SELECT %s AS bucket_key,\n       %s AS bucket_label,\n"
                    . "       COUNT(*) AS bucket_count\n%s\nWHERE %s\n"
                    . "GROUP BY %s, %s\nORDER BY %s\nLIMIT :aggregation_limit",
                $keyExpression,
                $labelExpression,
                $this->fromSql(),
                $conditions,
                $keyExpression,
                $labelExpression,
                $sortByLabel
                    ? sprintf('%s ASC NULLS LAST, bucket_count DESC', $labelExpression)
                    : sprintf('bucket_count DESC, %s ASC NULLS LAST', $labelExpression),
            ),
            $parameters,
            $types,
        );

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[] = new AggregationBucket(
                $this->nullableString($row, 'bucket_key'),
                $this->nullableString($row, 'bucket_label'),
                $this->integer($row, 'bucket_count'),
            );
        }

        return $buckets;
    }

    public function matrix(
        SearchScope $scope,
        CompiledQuery $compiled,
        AggregationField $rows,
        AggregationField $columns,
        int $limitPerAxis,
    ): array {
        if ($scope->isEmpty()) {
            return [];
        }

        [$rowKey, $rowLabel] = self::GROUPING[$rows->value];
        [$columnKey, $columnLabel] = self::GROUPING[$columns->value];
        $parameters = $compiled->parameters;
        $types = $compiled->parameterTypes;
        $conditions = $this->predicate($scope, $compiled, $parameters, $types);

        // The cap is on cells, not on either axis alone: a matrix bounded per
        // axis could still return the product of both.
        $parameters['aggregation_limit'] = $limitPerAxis * $limitPerAxis;
        $types['aggregation_limit'] = ParameterType::INTEGER;

        $result = [];

        foreach ($this->execute(
            sprintf(
                "SELECT %s AS row_key,\n       %s AS row_label,\n"
                    . "       %s AS column_key,\n       %s AS column_label,\n"
                    . "       COUNT(*) AS cell_count\n%s\nWHERE %s\n"
                    . "GROUP BY %s, %s, %s, %s\n"
                    . "ORDER BY cell_count DESC, row_label ASC NULLS LAST,"
                    . " column_label ASC NULLS LAST\nLIMIT :aggregation_limit",
                $rowKey,
                $rowLabel,
                $columnKey,
                $columnLabel,
                $this->fromSql(),
                $conditions,
                $rowKey,
                $rowLabel,
                $columnKey,
                $columnLabel,
            ),
            $parameters,
            $types,
        ) as $row) {
            $result[] = new AggregationCell(
                $this->nullableString($row, 'row_key'),
                $this->nullableString($row, 'row_label'),
                $this->nullableString($row, 'column_key'),
                $this->nullableString($row, 'column_label'),
                $this->integer($row, 'cell_count'),
            );
        }

        return $result;
    }

    public function timeSeries(
        SearchScope $scope,
        CompiledQuery $compiled,
        TimeSeriesEvent $event,
        TimeSeriesBucket $bucket,
        int $rangeDays,
    ): array {
        if ($scope->isEmpty()) {
            return [];
        }

        $column = self::EVENT_COLUMNS[$event->value];
        $unit = self::BUCKET_UNITS[$bucket->value];
        $parameters = $compiled->parameters;
        $types = $compiled->parameterTypes;
        $conditions = $this->predicate($scope, $compiled, $parameters, $types);

        $parameters['aggregation_range'] = $rangeDays;
        $types['aggregation_range'] = ParameterType::INTEGER;

        // `generate_series` fills the empty buckets, so a gap in the data reads
        // as zero rather than as a missing point the chart would interpolate
        // across.
        $sql = sprintf(
            <<<'SQL'
                WITH buckets AS (
                    SELECT generate_series(
                        date_trunc('%1$s', CURRENT_TIMESTAMP)
                            - make_interval(days => :aggregation_range),
                        date_trunc('%1$s', CURRENT_TIMESTAMP),
                        make_interval(%1$ss => 1)
                    ) AS bucket_start
                ),
                counted AS (
                    SELECT date_trunc('%1$s', %2$s) AS bucket_start,
                           COUNT(*) AS bucket_count
                    %3$s
                    WHERE %4$s
                        AND %2$s IS NOT NULL
                        AND %2$s >= date_trunc('%1$s', CURRENT_TIMESTAMP)
                            - make_interval(days => :aggregation_range)
                    GROUP BY 1
                )
                SELECT buckets.bucket_start,
                       COALESCE(counted.bucket_count, 0) AS bucket_count
                FROM buckets
                LEFT JOIN counted ON counted.bucket_start = buckets.bucket_start
                ORDER BY buckets.bucket_start
                SQL,
            $unit,
            $column,
            $this->fromSql(),
            $conditions,
        );

        $points = [];

        foreach ($this->execute($sql, $parameters, $types) as $row) {
            $points[] = new TimeSeriesPoint(
                $this->moment($this->string($row, 'bucket_start')),
                $this->integer($row, 'bucket_count'),
            );
        }

        return $points;
    }

    /**
     * The scope predicate comes first and the compiled filter is appended to
     * it, so no compiled fragment can widen the row set.
     *
     * @param array<string, list<int>|list<string>|int|string> $parameters
     * @param array<string, ArrayParameterType|ParameterType>  $types
     */
    private function predicate(
        SearchScope $scope,
        CompiledQuery $compiled,
        array &$parameters,
        array &$types,
    ): string {
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

        return implode("\n    AND ", $conditions);
    }

    /**
     * The same joins search uses, so a grouping label resolves exactly as the
     * corresponding column would in a result table.
     */
    private function fromSql(): string
    {
        return <<<'SQL'
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
            SQL;
    }

    /**
     * @param array<string, list<int>|list<string>|int|string> $parameters
     * @param array<string, ArrayParameterType|ParameterType>  $types
     *
     * @return list<array<string, mixed>>
     */
    private function execute(string $sql, array $parameters, array $types): array
    {
        $inTransaction = $this->connection->isTransactionActive();

        try {
            $this->connection->executeStatement(sprintf(
                'SET %sstatement_timeout = %d',
                $inTransaction ? 'LOCAL ' : '',
                $this->timeoutMilliseconds,
            ));

            return $this->connection->fetchAllAssociative($sql, $parameters, $types);
        } catch (DriverException $exception) {
            if ($exception->getSQLState() === self::TIMEOUT_SQL_STATE) {
                throw new QueryTimedOutException(
                    'The aggregation exceeded the configured statement timeout.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        } finally {
            if (!$inTransaction) {
                $this->connection->executeStatement('SET statement_timeout = DEFAULT');
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function moment(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }
}
