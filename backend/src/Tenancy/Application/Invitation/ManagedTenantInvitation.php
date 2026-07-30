<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;

final readonly class ManagedTenantInvitation
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $email,
        public string $status,
        public string $invitedByDisplayName,
        public ?string $initialRoleCode,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $revokedAt,
    ) {}
}
