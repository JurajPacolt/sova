<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Doctrine\DBAL\Connection;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class TenantRoleAssignmentService
{
    public function __construct(
        private Connection $connection,
        private TenantRoleRepository $roles,
        private TenantOwnershipGuard $ownership,
        private SecurityAuditRecorder $audit,
    ) {}

    public function assign(
        string $tenantId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        bool $mayManageOwners,
        ?string $effectiveUserId = null,
    ): void {
        $this->connection->transactional(function () use (
            $tenantId,
            $membershipId,
            $roleId,
            $actorUserId,
            $requestId,
            $ipAddress,
            $mayManageOwners,
            $effectiveUserId,
        ): void {
            $this->assertTenantAvailable($tenantId);
            $membershipStatus = $this->roles->membershipStatusForUpdate(
                $tenantId,
                $membershipId,
            );

            if ($membershipStatus === null) {
                throw $this->membershipNotFound();
            }

            if ($membershipStatus !== 'ACTIVE') {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_MEMBERSHIP_INACTIVE',
                    'Roles can be assigned only to an active tenant membership.',
                );
            }

            $role = $this->roleForUpdate($tenantId, $roleId);
            $this->assertRoleActive($role);
            $this->ownership->assertMayManageRole(
                $role,
                $mayManageOwners,
            );

            if ($this->roles->assignmentExists(
                $tenantId,
                $membershipId,
                $roleId,
            )) {
                return;
            }

            $this->roles->assign(
                $tenantId,
                $membershipId,
                $roleId,
                $actorUserId,
            );
            $this->audit->record(
                eventType: 'TENANT_ROLE_ASSIGNED',
                outcome: 'SUCCESS',
                reasonCode: 'ROLE_ASSIGNED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'membership_id' => $membershipId,
                    'role_id' => $roleId,
                    'role_code' => $role->code,
                ],
            );
        });
    }

    public function unassign(
        string $tenantId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        bool $mayManageOwners,
        ?string $effectiveUserId = null,
    ): void {
        $this->connection->transactional(function () use (
            $tenantId,
            $membershipId,
            $roleId,
            $actorUserId,
            $requestId,
            $ipAddress,
            $mayManageOwners,
            $effectiveUserId,
        ): void {
            $this->assertTenantAvailable($tenantId);
            $membershipStatus = $this->roles->membershipStatusForUpdate(
                $tenantId,
                $membershipId,
            );

            if ($membershipStatus === null) {
                throw $this->membershipNotFound();
            }

            $role = $this->roleForUpdate($tenantId, $roleId);
            $this->ownership->assertMayManageRole(
                $role,
                $mayManageOwners,
            );

            if (!$this->roles->assignmentExists(
                $tenantId,
                $membershipId,
                $roleId,
            )) {
                return;
            }

            $this->ownership->assertCanRemoveOwnerRole(
                tenantId: $tenantId,
                membershipId: $membershipId,
                membershipStatus: $membershipStatus,
                role: $role,
                mayManageOwners: $mayManageOwners,
            );

            $this->roles->unassign($tenantId, $membershipId, $roleId);
            $this->audit->record(
                eventType: 'TENANT_ROLE_UNASSIGNED',
                outcome: 'SUCCESS',
                reasonCode: 'ROLE_UNASSIGNED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'membership_id' => $membershipId,
                    'role_id' => $roleId,
                    'role_code' => $role->code,
                ],
            );
        });
    }

    private function assertTenantAvailable(string $tenantId): void
    {
        if ($this->roles->lockActiveTenant($tenantId)) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::Conflict,
            'TENANT_ROLE_OPERATION_UNAVAILABLE',
            'Tenant roles cannot be changed in the current tenant state.',
        );
    }

    private function roleForUpdate(
        string $tenantId,
        string $roleId,
    ): TenantRoleDetails {
        $role = $this->roles->findForTenant($tenantId, $roleId, true);

        if ($role !== null) {
            return $role;
        }

        throw new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_ROLE_NOT_FOUND',
            'The tenant role was not found.',
        );
    }

    private function assertRoleActive(TenantRoleDetails $role): void
    {
        if ($role->status !== 'ACTIVE') {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'TENANT_ROLE_INACTIVE',
                'An archived tenant role cannot be assigned.',
            );
        }
    }

    private function membershipNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_MEMBERSHIP_NOT_FOUND',
            'The tenant membership was not found.',
        );
    }
}
