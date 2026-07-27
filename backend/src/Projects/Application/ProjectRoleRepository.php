<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

interface ProjectRoleRepository
{
    /**
     * @return list<ProjectRoleDetails>
     */
    public function listForProject(string $tenantId, string $projectId): array;

    public function findForProject(
        string $tenantId,
        string $projectId,
        string $roleId,
        bool $forUpdate = false,
    ): ?ProjectRoleDetails;

    public function findByCode(
        string $tenantId,
        string $projectId,
        string $code,
    ): ?ProjectRoleDetails;

    /**
     * @return list<ProjectMemberDetails>
     */
    public function listMembers(string $tenantId, string $projectId): array;

    public function assignmentExists(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
    ): bool;

    public function assign(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
    ): void;

    public function unassign(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
    ): void;

    /**
     * @return list<ProjectWorkgroupLinkDetails>
     */
    public function listWorkgroupLinks(string $tenantId, string $projectId): array;

    public function workgroupLinkExists(
        string $tenantId,
        string $projectId,
        string $workgroupId,
    ): bool;

    public function workgroupStatus(
        string $tenantId,
        string $workgroupId,
    ): ?string;

    public function linkWorkgroup(
        string $tenantId,
        string $projectId,
        string $workgroupId,
        string $roleId,
        string $actorUserId,
    ): void;

    public function unlinkWorkgroup(
        string $tenantId,
        string $projectId,
        string $workgroupId,
    ): void;
}
