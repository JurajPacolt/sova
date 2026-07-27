<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use DateTimeImmutable;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Projects\Domain\ProjectVisibility;

final readonly class ProjectDetails
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $code,
        public string $name,
        public string $description,
        public ProjectVisibility $visibility,
        public ProjectStatus $status,
        public ?string $leadMembershipId,
        public ?string $leadDisplayName,
        public ?string $leadEmail,
        public int $memberCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
