<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use DateTimeImmutable;

/**
 * One authorised search hit. The projection is deliberately narrow — enough for
 * a result table or a list widget, nothing more. The description is not exposed
 * here even though fulltext searches it, so a match cannot leak body text the
 * caller has not opened the issue to read.
 */
final readonly class SearchResultItem
{
    public function __construct(
        public string $id,
        public string $key,
        public string $title,
        public string $projectId,
        public string $projectCode,
        public string $projectName,
        public string $issueTypeCode,
        public string $issueTypeName,
        public int $hierarchyLevel,
        public string $statusCode,
        public string $statusName,
        public string $statusCategory,
        public string $priority,
        public ?string $assigneeMembershipId,
        public ?string $assigneeDisplayName,
        public ?string $assigneeWorkgroupId,
        public ?string $assigneeWorkgroupName,
        public ?string $parentIssueKey,
        public ?string $resolution,
        public ?DateTimeImmutable $resolvedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
