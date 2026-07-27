<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;

final readonly class TenantInvitation
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $tenantName,
        public string $tenantSlug,
        public string $email,
        public string $normalizedEmail,
        public string $invitedByUserId,
        public string $invitedByDisplayName,
        public ?string $initialRoleCode,
        public DateTimeImmutable $expiresAt,
    ) {}
}
