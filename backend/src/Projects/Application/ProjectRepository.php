<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Sova\Projects\Domain\ProjectStatus;
use Sova\Projects\Domain\ProjectVisibility;

interface ProjectRepository
{
    /**
     * Lists every project of the tenant. Reserved for callers holding the
     * tenant-wide `tenant.projects.manage` permission.
     *
     * @return list<ProjectListItem>
     */
    public function listForTenant(string $tenantId, string $viewerUserId): array;

    /**
     * Lists the projects the user may see: tenant-visible ones plus private
     * ones reachable through a direct role assignment or a linked workgroup.
     * Returns an empty list when the user has no active membership.
     *
     * @return list<ProjectListItem>
     */
    public function listVisibleForUser(string $tenantId, string $userId): array;

    public function findForTenant(
        string $tenantId,
        string $projectId,
        bool $forUpdate = false,
    ): ?ProjectDetails;

    public function create(
        string $projectId,
        string $tenantId,
        string $code,
        string $name,
        string $description,
        ProjectVisibility $visibility,
        ?string $leadMembershipId,
        string $createdByUserId,
    ): void;

    public function changeStatus(
        string $tenantId,
        string $projectId,
        ProjectStatus $status,
    ): void;

    public function membershipStatus(
        string $tenantId,
        string $membershipId,
    ): ?string;
}
