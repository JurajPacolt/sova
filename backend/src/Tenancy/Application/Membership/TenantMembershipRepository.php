<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Membership;

interface TenantMembershipRepository
{
    /**
     * @return list<TenantMembershipDetails>
     */
    public function listForTenant(string $tenantId): array;

    public function findForTenant(
        string $tenantId,
        string $membershipId,
        bool $forUpdate = false,
    ): ?TenantMembershipDetails;

    public function lockActiveTenant(string $tenantId): bool;

    public function changeStatus(
        string $tenantId,
        string $membershipId,
        string $status,
    ): void;
}
