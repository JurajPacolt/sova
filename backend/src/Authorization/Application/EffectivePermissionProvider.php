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
}
