<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\ConfigurationStatus;
use Sova\ProjectConfiguration\Domain\HierarchyLevel;

final readonly class IssueTypeDetails
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $code,
        public string $name,
        public string $description,
        public HierarchyLevel $hierarchyLevel,
        public int $position,
        public ConfigurationStatus $status,
        public int $version,
        public ?string $workflowId,
    ) {}
}
