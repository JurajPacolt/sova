<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Sova\Authorization\Domain\Permission;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class AuthorizationService
{
    public function __construct(
        private EffectivePermissionProvider $permissions,
    ) {}

    public function isGranted(
        AuthorizationSubject $subject,
        Permission $permission,
        AuthorizationScope $scope,
    ): bool {
        if (!$scope->supports($permission)) {
            return false;
        }

        if ($subject->hasSuperadminBypass()) {
            return true;
        }

        return $this->permissions->hasPermission(
            $subject->effectiveUserId,
            $permission,
            $scope,
        );
    }

    /**
     * The subject's effective permissions in the scope, for UI affordances.
     * Never a substitute for `require()` on the operation itself.
     *
     * @return list<Permission>
     */
    public function grantedPermissions(
        AuthorizationSubject $subject,
        AuthorizationScope $scope,
    ): array {
        if ($subject->hasSuperadminBypass()) {
            return array_values(array_filter(
                Permission::cases(),
                static fn(Permission $permission): bool => $scope->supports($permission),
            ));
        }

        return array_values(array_filter(
            $this->permissions->listPermissions($subject->effectiveUserId, $scope),
            static fn(Permission $permission): bool => $scope->supports($permission),
        ));
    }

    public function require(
        AuthorizationSubject $subject,
        Permission $permission,
        AuthorizationScope $scope,
    ): void {
        if ($this->isGranted($subject, $permission, $scope)) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::PermissionDenied,
            'PERMISSION_DENIED',
            'You do not have permission to perform this operation.',
        );
    }
}
