<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Authorization\Application\AuthorizationSubject;

/**
 * The public way to aggregate issues with SovaQL.
 *
 * It plans the query through {@see IssueSearchService::plan()} rather than
 * repeating the steps, so aggregation runs inside exactly the same authorised
 * scope as search and cannot drift from it. Everything is counted **after** the
 * scope predicate is applied — a total computed over rows the caller may not
 * see would disclose their existence just as surely as returning them.
 */
final readonly class IssueAggregationService
{
    public function __construct(
        private IssueSearchService $search,
        private IssueAggregationRepository $repository,
    ) {}

    public function count(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
    ): int {
        $plan = $this->search->plan($subject, $tenantId, $query);

        return $this->repository->count($plan->scope, $plan->compiled);
    }

    /**
     * @return list<AggregationBucket>
     */
    public function breakdown(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        AggregationField $field,
        int $limit,
        bool $includeEmpty,
        bool $sortByLabel,
    ): array {
        $plan = $this->search->plan($subject, $tenantId, $query);

        return $this->repository->breakdown(
            $plan->scope,
            $plan->compiled,
            $field,
            $limit,
            $includeEmpty,
            $sortByLabel,
        );
    }

    /**
     * @return list<AggregationCell>
     */
    public function matrix(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        AggregationField $rows,
        AggregationField $columns,
        int $limitPerAxis,
    ): array {
        $plan = $this->search->plan($subject, $tenantId, $query);

        return $this->repository->matrix(
            $plan->scope,
            $plan->compiled,
            $rows,
            $columns,
            $limitPerAxis,
        );
    }

    /**
     * @return list<TimeSeriesPoint>
     */
    public function timeSeries(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        TimeSeriesEvent $event,
        TimeSeriesBucket $bucket,
        int $rangeDays,
    ): array {
        $plan = $this->search->plan($subject, $tenantId, $query);

        return $this->repository->timeSeries(
            $plan->scope,
            $plan->compiled,
            $event,
            $bucket,
            $rangeDays,
        );
    }
}
