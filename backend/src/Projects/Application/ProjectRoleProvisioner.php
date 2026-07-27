<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

interface ProjectRoleProvisioner
{
    public function provisionDefaults(
        string $tenantId,
        string $projectId,
        ?string $createdByUserId = null,
    ): void;
}
