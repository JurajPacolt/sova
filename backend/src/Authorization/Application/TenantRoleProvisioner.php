<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

interface TenantRoleProvisioner
{
    public function provisionDefaults(
        string $tenantId,
        ?string $createdByUserId = null,
    ): void;
}
