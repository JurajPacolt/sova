<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use DateTimeImmutable;
use Sova\Issues\Domain\IssuePriority;
use Sova\ProjectConfiguration\Domain\StatusCategory;

final readonly class IssueDetails
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
        public string $issueTypeCode,
        public string $issueTypeName,
        public string $workflowVersionId,
        public string $statusId,
        public string $statusCode,
        public string $statusName,
        public StatusCategory $statusCategory,
        public ?string $parentIssueId,
        public ?string $parentIssueKey,
        public string $reporterMembershipId,
        public ?string $reporterDisplayName,
        public ?string $assigneeMembershipId,
        public ?string $assigneeDisplayName,
        public ?string $assigneeWorkgroupId,
        public ?string $assigneeWorkgroupName,
        public IssuePriority $priority,
        public ?string $resolution,
        public ?DateTimeImmutable $resolvedAt,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
