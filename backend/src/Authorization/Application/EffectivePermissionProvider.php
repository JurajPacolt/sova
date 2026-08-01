<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Sova\Authorization\Domain\Permission;

interface EffectivePermissionProvider
{
    public function hasPermission(
        string $userId,
        Permission $permission,
        AuthorizationScope $scope,
    ): bool;

    /**
     * Every permission the user effectively holds in the scope. Callers must
     * still drop permissions the scope does not support: a tenant role may
     * carry project-scoped codes that only apply inside a project.
     *
     * @return list<Permission>
     */
    public function listPermissions(string $userId, AuthorizationScope $scope): array;
}
