<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * How many issues currently sit on one status of the workflow that is about to
 * change. Drives the "affected issues" summary and the migration requirement.
 */
final readonly class StatusIssueCount
{
    public function __construct(
        public string $statusId,
        public string $statusCode,
        public string $statusName,
        public int $count,
    ) {}
}
