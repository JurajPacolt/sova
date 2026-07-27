<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

final readonly class AcceptedInvitation
{
    public function __construct(
        public string $userId,
        public string $tenantId,
        public string $tenantSlug,
        public bool $membershipCreated,
    ) {}
}
