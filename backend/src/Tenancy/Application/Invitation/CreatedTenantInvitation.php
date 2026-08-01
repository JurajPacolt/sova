<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;

final readonly class CreatedTenantInvitation
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $email,
        public DateTimeImmutable $expiresAt,
    ) {}
}
