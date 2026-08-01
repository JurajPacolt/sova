<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\ConfigurationStatus;
use Sova\ProjectConfiguration\Domain\StatusCategory;

final readonly class StatusDetails
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $code,
        public string $name,
        public string $description,
        public StatusCategory $category,
        public int $position,
        public ConfigurationStatus $status,
    ) {}
}
