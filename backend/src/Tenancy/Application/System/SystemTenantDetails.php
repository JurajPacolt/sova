<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

use DateTimeImmutable;
use Sova\Tenancy\Domain\Tenant\TenantStatus;

final readonly class SystemTenantDetails
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public TenantStatus $status,
        public int $revision,
        public ?string $ownerEmail,
        public int $activeMemberCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletionEffectiveAt,
    ) {}
}
