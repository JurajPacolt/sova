<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use Sova\Issues\Domain\IssuePriority;

final readonly class CreateIssueInput
{
    public function __construct(
        public string $issueTypeId,
        public string $title,
        public string $description,
        public ?string $parentIssueId,
        public ?string $assigneeMembershipId,
        public ?string $assigneeWorkgroupId,
        public IssuePriority $priority,
    ) {}
}
