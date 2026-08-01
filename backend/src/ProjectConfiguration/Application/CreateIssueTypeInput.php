<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\HierarchyLevel;

final readonly class CreateIssueTypeInput
{
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public HierarchyLevel $hierarchyLevel,
        public int $position,
        public string $icon,
        public string $colorToken,
        public string $workflowId,
        public int $expectedConfigVersion,
    ) {}
}
