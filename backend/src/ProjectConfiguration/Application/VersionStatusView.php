<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\StatusCategory;

/**
 * A status as it participates in one workflow version: the project-level
 * status data plus its position inside this version.
 */
final readonly class VersionStatusView
{
    public function __construct(
        public string $statusId,
        public string $code,
        public string $name,
        public StatusCategory $category,
        public string $colorToken,
        public int $position,
    ) {}
}
