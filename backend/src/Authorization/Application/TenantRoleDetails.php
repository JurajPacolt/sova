<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

final readonly class TenantRoleDetails
{
    /**
     * @param list<string> $permissionCodes
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $code,
        public string $name,
        public string $description,
        public string $status,
        public bool $isSystem,
        public bool $isEditable,
        public int $revision,
        public array $permissionCodes,
        public int $assignmentCount,
    ) {}
}
