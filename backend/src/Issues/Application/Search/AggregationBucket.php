<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * One group of a breakdown.
 *
 * {@see $key} is null for the "no value" bucket — unassigned issues, issues
 * with no workgroup. It is reported rather than dropped, because a chart that
 * silently omits them adds up to less than the total and quietly misleads.
 */
final readonly class AggregationBucket
{
    public function __construct(
        public ?string $key,
        public ?string $label,
        public int $count,
    ) {}
}
