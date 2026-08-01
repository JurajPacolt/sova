<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Membership;

final readonly class TenantMembershipRoleDetails
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $status,
    ) {}
}
