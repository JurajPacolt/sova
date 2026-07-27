<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

use Sova\Workgroups\Domain\WorkgroupMemberRole;
use Sova\Workgroups\Domain\WorkgroupStatus;

interface WorkgroupRepository
{
    /**
     * @return list<WorkgroupDetails>
     */
    public function listForTenant(string $tenantId): array;

    public function findForTenant(
        string $tenantId,
        string $workgroupId,
        bool $forUpdate = false,
    ): ?WorkgroupDetails;

    public function create(
        string $workgroupId,
        string $tenantId,
        string $name,
        string $description,
        string $createdByUserId,
    ): void;

    public function changeStatus(
        string $tenantId,
        string $workgroupId,
        WorkgroupStatus $status,
    ): void;

    /**
     * @return list<WorkgroupMemberDetails>
     */
    public function listMembers(string $tenantId, string $workgroupId): array;

    public function membershipStatus(
        string $tenantId,
        string $membershipId,
    ): ?string;

    public function memberRole(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
        bool $forUpdate = false,
    ): ?WorkgroupMemberRole;

    public function upsertMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
        WorkgroupMemberRole $role,
        string $addedByUserId,
    ): void;

    public function removeMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
    ): void;
}
