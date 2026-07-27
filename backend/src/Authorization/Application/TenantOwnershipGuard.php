<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Sova\Authorization\Domain\DefaultRole;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class TenantOwnershipGuard
{
    public function __construct(private TenantRoleRepository $roles) {}

    public function assertMayManageRole(
        TenantRoleDetails $role,
        bool $mayManageOwners,
    ): void {
        if (
            $role->code !== DefaultRole::TenantOwner->value
            || $mayManageOwners
        ) {
            return;
        }

        throw $this->permissionDenied();
    }

    public function assertCanRemoveOwnerRole(
        string $tenantId,
        string $membershipId,
        string $membershipStatus,
        TenantRoleDetails $role,
        bool $mayManageOwners,
    ): void {
        $this->assertMayManageRole($role, $mayManageOwners);

        if (
            $role->code !== DefaultRole::TenantOwner->value
            || $membershipStatus !== 'ACTIVE'
        ) {
            return;
        }

        $this->assertAnotherActiveOwnerExists($tenantId, $membershipId);
    }

    public function assertCanChangeMembershipStatus(
        string $tenantId,
        string $membershipId,
        string $currentStatus,
        string $targetStatus,
        bool $mayManageOwners,
    ): void {
        if (!$this->roles->membershipHasRoleCode(
            $tenantId,
            $membershipId,
            DefaultRole::TenantOwner->value,
        )) {
            return;
        }

        if (!$mayManageOwners) {
            throw $this->permissionDenied();
        }

        if ($currentStatus === 'ACTIVE' && $targetStatus !== 'ACTIVE') {
            $this->assertAnotherActiveOwnerExists(
                $tenantId,
                $membershipId,
            );
        }
    }

    private function assertAnotherActiveOwnerExists(
        string $tenantId,
        string $membershipId,
    ): void {
        if ($this->roles->activeOwnerCount($tenantId) > 1) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::Conflict,
            'TENANT_LAST_OWNER_REQUIRED',
            'The tenant must retain at least one active owner.',
        );
    }

    private function permissionDenied(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::PermissionDenied,
            'PERMISSION_DENIED',
            'You do not have permission to perform this operation.',
        );
    }
}
