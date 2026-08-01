<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

final readonly class SystemTenantCreationResult
{
    public function __construct(
        public SystemTenantDetails $tenant,
        public string $ownerInvitationEmail,
        public bool $replayed,
    ) {}
}
