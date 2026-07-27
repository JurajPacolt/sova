<?php

declare(strict_types=1);

namespace Sova\Workgroups\Presentation\Http;

use Sova\Workgroups\Application\WorkgroupDetails;
use Sova\Workgroups\Application\WorkgroupMemberDetails;

final class WorkgroupSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(WorkgroupDetails $workgroup): array
    {
        return [
            'id' => $workgroup->id,
            'tenant_id' => $workgroup->tenantId,
            'name' => $workgroup->name,
            'description' => $workgroup->description,
            'status' => $workgroup->status->value,
            'member_count' => $workgroup->memberCount,
            'created_at' => $workgroup->createdAt->format(DATE_ATOM),
            'updated_at' => $workgroup->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMember(WorkgroupMemberDetails $member): array
    {
        return [
            'membership_id' => $member->membershipId,
            'user' => [
                'id' => $member->userId,
                'email' => $member->email,
                'display_name' => $member->displayName,
            ],
            'role' => $member->role->value,
            'joined_at' => $member->joinedAt->format(DATE_ATOM),
        ];
    }
}
