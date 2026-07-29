<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\StatusCategory;

/**
 * A status the draft version contains, referenced by its project-stable code.
 * A code that already exists in the project is reused (keeping its identity and
 * any issues on it); a new code creates a new project status.
 */
final readonly class DraftStatusInput
{
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public StatusCategory $category,
        public string $colorToken,
        public int $position,
    ) {}
}
