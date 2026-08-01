<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http;

use Sova\Tenancy\Application\System\SystemTenantDetails;

final class SystemTenantSerializer
{
    /**
     * @return array<string, int|string|null>
     */
    public function serialize(SystemTenantDetails $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'revision' => $tenant->revision,
            'owner_email' => $tenant->ownerEmail,
            'active_member_count' => $tenant->activeMemberCount,
            'created_at' => $tenant->createdAt->format(DATE_ATOM),
            'updated_at' => $tenant->updatedAt->format(DATE_ATOM),
            'deletion_effective_at' => $tenant->deletionEffectiveAt?->format(
                DATE_ATOM,
            ),
        ];
    }
}
