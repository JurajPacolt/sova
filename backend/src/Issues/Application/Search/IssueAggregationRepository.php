<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * Aggregates a compiled query.
 *
 * Implementations must apply the tenant and project predicate **before**
 * grouping, never after: a count computed over rows the caller may not see and
 * filtered afterwards would already have told them those rows exist. Every
 * statement runs under the same statement timeout as search.
 */
interface IssueAggregationRepository
{
    /**
     * @throws QueryTimedOutException
     */
    public function count(SearchScope $scope, CompiledQuery $compiled): int;

    /**
     * @return list<AggregationBucket> ordered by count descending, or by label
     *                                 when $sortByLabel
     *
     * @throws QueryTimedOutException
     */
    public function breakdown(
        SearchScope $scope,
        CompiledQuery $compiled,
        AggregationField $field,
        int $limit,
        bool $includeEmpty,
        bool $sortByLabel,
    ): array;

    /**
     * @return list<AggregationCell>
     *
     * @throws QueryTimedOutException
     */
    public function matrix(
        SearchScope $scope,
        CompiledQuery $compiled,
        AggregationField $rows,
        AggregationField $columns,
        int $limitPerAxis,
    ): array;

    /**
     * @return list<TimeSeriesPoint> one point per bucket in the range, including
     *                               the empty ones
     *
     * @throws QueryTimedOutException
     */
    public function timeSeries(
        SearchScope $scope,
        CompiledQuery $compiled,
        TimeSeriesEvent $event,
        TimeSeriesBucket $bucket,
        int $rangeDays,
    ): array;
}
