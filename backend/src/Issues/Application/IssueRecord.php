<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use Sova\Issues\Domain\IssuePriority;

/** A row ready to be inserted, with every reference already validated. */
final readonly class IssueRecord
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $projectId,
        public int $number,
        public string $key,
        public string $title,
        public string $description,
        public string $issueTypeId,
        public string $workflowVersionId,
        public string $statusId,
        public ?string $parentIssueId,
        public string $reporterMembershipId,
        public ?string $assigneeMembershipId,
        public ?string $assigneeWorkgroupId,
        public IssuePriority $priority,
        public string $createdByUserId,
    ) {}
}
