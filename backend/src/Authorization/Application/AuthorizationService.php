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
