<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Membership;

use DateTimeImmutable;

final readonly class TenantMembershipDetails
{
    /**
     * @param list<TenantMembershipRoleDetails> $roles
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public string $email,
        public string $displayName,
        public string $status,
        public DateTimeImmutable $joinedAt,
        public array $roles,
    ) {}
}
