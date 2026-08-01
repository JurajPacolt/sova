<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Sova\Projects\Domain\ProjectVisibility;

final readonly class CreateProjectInput
{
    public function __construct(
        public string $code,
        public string $name,
        public string $description,
        public ProjectVisibility $visibility,
        public ?string $leadMembershipId,
    ) {}
}
