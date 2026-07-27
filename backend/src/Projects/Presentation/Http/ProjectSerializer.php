<?php

declare(strict_types=1);

namespace Sova\Projects\Presentation\Http;

use Sova\Projects\Application\ProjectDetails;
use Sova\Projects\Application\ProjectMemberDetails;
use Sova\Projects\Application\ProjectMemberRoleDetails;
use Sova\Projects\Application\ProjectRoleDetails;
use Sova\Projects\Application\ProjectWorkgroupLinkDetails;

final class ProjectSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(ProjectDetails $project): array
    {
        return [
            'id' => $project->id,
            'tenant_id' => $project->tenantId,
            'code' => $project->code,
            'name' => $project->name,
            'description' => $project->description,
            'visibility' => $project->visibility->value,
            'status' => $project->status->value,
            'lead' => $project->leadMembershipId === null ? null : [
                'membership_id' => $project->leadMembershipId,
                'display_name' => $project->leadDisplayName,
                'email' => $project->leadEmail,
            ],
            'member_count' => $project->memberCount,
            'created_at' => $project->createdAt->format(DATE_ATOM),
            'updated_at' => $project->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRole(ProjectRoleDetails $role): array
    {
        return [
            'id' => $role->id,
            'project_id' => $role->projectId,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'status' => $role->status,
            'is_system' => $role->isSystem,
            'is_editable' => $role->isEditable,
            'revision' => $role->revision,
            'permissions' => $role->permissionCodes,
            'assignment_count' => $role->assignmentCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMember(ProjectMemberDetails $member): array
    {
        return [
            'membership_id' => $member->membershipId,
            'user' => [
                'id' => $member->userId,
                'email' => $member->email,
                'display_name' => $member->displayName,
            ],
            'roles' => array_map($this->serializeMemberRole(...), $member->roles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMemberRole(ProjectMemberRoleDetails $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeWorkgroupLink(ProjectWorkgroupLinkDetails $link): array
    {
        return [
            'workgroup_id' => $link->workgroupId,
            'workgroup_name' => $link->workgroupName,
            'role' => [
                'id' => $link->roleId,
                'code' => $link->roleCode,
                'name' => $link->roleName,
            ],
        ];
    }
}
