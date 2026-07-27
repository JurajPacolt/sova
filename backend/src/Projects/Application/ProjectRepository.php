<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Sova\Projects\Domain\ProjectStatus;
use Sova\Projects\Domain\ProjectVisibility;

interface ProjectRepository
{
    /**
     * @return list<ProjectDetails>
     */
    public function listForTenant(string $tenantId): array;

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
