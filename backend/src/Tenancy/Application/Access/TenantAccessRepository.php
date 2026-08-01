<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Access;

interface TenantAccessRepository
{
    /**
     * @return list<AccessibleTenant>
     */
    public function listAccessibleTo(
        string $userId,
        bool $isSuperadmin,
    ): array;

    public function findAccessibleById(
        string $tenantId,
        string $userId,
        bool $isSuperadmin,
    ): ?AccessibleTenant;
}
