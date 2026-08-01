<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http;

use Sova\Tenancy\Application\Membership\TenantMembershipDetails;
use Sova\Tenancy\Application\Membership\TenantMembershipRoleDetails;

final class TenantMembershipSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(TenantMembershipDetails $membership): array
    {
        return [
            'id' => $membership->id,
            'user' => [
                'id' => $membership->userId,
                'email' => $membership->email,
                'display_name' => $membership->displayName,
            ],
            'status' => $membership->status,
            'joined_at' => $membership->joinedAt->format(DATE_ATOM),
            'roles' => array_map(
                $this->serializeRole(...),
                $membership->roles,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function serializeRole(
        TenantMembershipRoleDetails $role,
    ): array {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'status' => $role->status,
        ];
    }
}
