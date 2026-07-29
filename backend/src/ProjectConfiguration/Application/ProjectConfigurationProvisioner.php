<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

interface ProjectConfigurationProvisioner
{
    /**
     * Seeds a project with an independent copy of the default template: issue
     * types, statuses, one published workflow version and the type mapping.
     * Must run inside the project creation transaction so a project can never
     * exist without a usable configuration.
     */
    public function provisionDefaults(
        string $tenantId,
        string $projectId,
        ?string $createdByUserId = null,
    ): void;
}
