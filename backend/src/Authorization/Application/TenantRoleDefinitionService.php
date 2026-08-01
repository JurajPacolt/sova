<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class TenantRoleDefinitionService
{
    public function __construct(
        private Connection $connection,
        private TenantRoleRepository $roles,
        private SecurityAuditRecorder $audit,
    ) {}

    public function create(
        string $tenantId,
        TenantRoleDefinitionInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId = null,
    ): TenantRoleDetails {
        $code = $input->code;

        if ($code === null) {
            throw new RuntimeException('Create role input must provide a code.');
        }

        return $this->connection->transactional(function () use (
            $tenantId,
            $input,
            $code,
            $actorUserId,
            $requestId,
            $ipAddress,
            $effectiveUserId,
        ): TenantRoleDetails {
            $this->assertTenantAvailable($tenantId);

            if ($this->roles->codeExists($tenantId, $code)) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_ROLE_CODE_CONFLICT',
                    'A tenant role with this code already exists.',
                );
            }

            $roleId = (string) UuidV7::generate();
            $this->roles->create(
                tenantId: $tenantId,
                roleId: $roleId,
                code: $code,
                name: $input->name,
                description: $input->description,
                actorUserId: $actorUserId,
            );
            $this->roles->replacePermissions(
                $tenantId,
                $roleId,
                $this->permissionCodes($input),
            );
            $role = $this->requireRole($tenantId, $roleId);
            $this->audit->record(
                eventType: 'TENANT_ROLE_CREATED',
                outcome: 'SUCCESS',
                reasonCode: 'ROLE_CREATED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'role_id' => $role->id,
                    'role_code' => $role->code,
                    'permission_count' => count($role->permissionCodes),
                    'permissions' => implode(',', $role->permissionCodes),
                ],
            );

            return $role;
        });
    }

    public function update(
        string $tenantId,
        string $roleId,
        TenantRoleDefinitionInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId = null,
    ): TenantRoleDetails {
        $expectedRevision = $input->expectedRevision;

        if ($expectedRevision === null) {
            throw new RuntimeException(
                'Update role input must provide an expected revision.',
            );
        }

        return $this->connection->transactional(function () use (
            $tenantId,
            $roleId,
            $input,
            $expectedRevision,
            $actorUserId,
            $requestId,
            $ipAddress,
            $effectiveUserId,
        ): TenantRoleDetails {
            $this->assertTenantAvailable($tenantId);
            $existing = $this->requireRole($tenantId, $roleId, true);
            $this->assertEditable($existing);

            if ($existing->status !== 'ACTIVE') {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_ROLE_INACTIVE',
                    'An archived tenant role cannot be changed.',
                );
            }

            if ($existing->revision !== $expectedRevision) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_ROLE_REVISION_CONFLICT',
                    'The tenant role was changed by another request.',
                );
            }

            $this->roles->updateDefinition(
                tenantId: $tenantId,
                roleId: $roleId,
                name: $input->name,
                description: $input->description,
            );
            $this->roles->replacePermissions(
                $tenantId,
                $roleId,
                $this->permissionCodes($input),
            );
            $updated = $this->requireRole($tenantId, $roleId);
            $this->audit->record(
                eventType: 'TENANT_ROLE_UPDATED',
                outcome: 'SUCCESS',
                reasonCode: 'ROLE_UPDATED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'role_id' => $updated->id,
                    'role_code' => $updated->code,
                    'previous_revision' => $existing->revision,
                    'revision' => $updated->revision,
                    'previous_permissions' => implode(
                        ',',
                        $existing->permissionCodes,
                    ),
                    'permissions' => implode(
                        ',',
                        $updated->permissionCodes,
                    ),
                ],
            );

            return $updated;
        });
    }

    public function archive(
        string $tenantId,
        string $roleId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId = null,
    ): void {
        $this->connection->transactional(function () use (
            $tenantId,
            $roleId,
            $actorUserId,
            $requestId,
            $ipAddress,
            $effectiveUserId,
        ): void {
            $this->assertTenantAvailable($tenantId);
            $existing = $this->requireRole($tenantId, $roleId, true);
            $this->assertEditable($existing);

            if ($existing->status === 'ARCHIVED') {
                return;
            }

            if ($existing->assignmentCount > 0) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_ROLE_ASSIGNED',
                    'A tenant role must be unassigned before it can be archived.',
                );
            }

            $this->roles->archive($tenantId, $roleId);
            $this->audit->record(
                eventType: 'TENANT_ROLE_ARCHIVED',
                outcome: 'SUCCESS',
                reasonCode: 'ROLE_ARCHIVED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'role_id' => $existing->id,
                    'role_code' => $existing->code,
                    'previous_revision' => $existing->revision,
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

    private function requireRole(
        string $tenantId,
        string $roleId,
        bool $forUpdate = false,
    ): TenantRoleDetails {
        $role = $this->roles->findForTenant($tenantId, $roleId, $forUpdate);

        if ($role !== null) {
            return $role;
        }

        throw new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_ROLE_NOT_FOUND',
            'The tenant role was not found.',
        );
    }

    private function assertEditable(TenantRoleDetails $role): void
    {
        if (!$role->isSystem && $role->isEditable) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::Conflict,
            'TENANT_ROLE_IMMUTABLE',
            'A system tenant role cannot be changed.',
        );
    }

    /**
     * @return list<string>
     */
    private function permissionCodes(TenantRoleDefinitionInput $input): array
    {
        return array_map(
            static fn($permission): string => $permission->value,
            $input->permissions,
        );
    }
}
