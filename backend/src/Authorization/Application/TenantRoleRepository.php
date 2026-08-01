<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

interface TenantRoleRepository
{
    /**
     * @return list<TenantRoleDetails>
     */
    public function listForTenant(string $tenantId): array;

    public function findForTenant(
        string $tenantId,
        string $roleId,
        bool $forUpdate = false,
    ): ?TenantRoleDetails;

    public function lockActiveTenant(string $tenantId): bool;

    public function codeExists(string $tenantId, string $code): bool;

    public function create(
        string $tenantId,
        string $roleId,
        string $code,
        string $name,
        string $description,
        string $actorUserId,
    ): void;

    /**
     * @param list<string> $permissionCodes
     */
    public function replacePermissions(
        string $tenantId,
        string $roleId,
        array $permissionCodes,
    ): void;

    public function updateDefinition(
        string $tenantId,
        string $roleId,
        string $name,
        string $description,
    ): void;

    public function archive(string $tenantId, string $roleId): void;

    public function membershipStatusForUpdate(
        string $tenantId,
        string $membershipId,
    ): ?string;

    public function assignmentExists(
        string $tenantId,
        string $membershipId,
        string $roleId,
    ): bool;

    public function assign(
        string $tenantId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
    ): void;

    public function unassign(
        string $tenantId,
        string $membershipId,
        string $roleId,
    ): void;

    public function membershipHasRoleCode(
        string $tenantId,
        string $membershipId,
        string $roleCode,
    ): bool;

    public function activeOwnerCount(string $tenantId): int;
}
