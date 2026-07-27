<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

final readonly class ProjectWorkgroupLinkDetails
{
    public function __construct(
        public string $workgroupId,
        public string $workgroupName,
        public string $roleId,
        public string $roleCode,
        public string $roleName,
    ) {}
}
