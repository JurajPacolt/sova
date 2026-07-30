<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http;

use Sova\Tenancy\Application\Invitation\CreatedTenantInvitation;
use Sova\Tenancy\Application\Invitation\ManagedTenantInvitation;

final class InvitationSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(
        ManagedTenantInvitation $invitation,
    ): array {
        return [
            'id' => $invitation->id,
            'tenant_id' => $invitation->tenantId,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'invited_by_display_name' => $invitation->invitedByDisplayName,
            'initial_role_code' => $invitation->initialRoleCode,
            'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
            'created_at' => $invitation->createdAt->format(DATE_ATOM),
            'updated_at' => $invitation->updatedAt->format(DATE_ATOM),
            'accepted_at' => $invitation->acceptedAt?->format(DATE_ATOM),
            'revoked_at' => $invitation->revokedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function serializeCreated(
        CreatedTenantInvitation $invitation,
    ): array {
        return [
            'id' => $invitation->id,
            'tenant_id' => $invitation->tenantId,
            'email' => $invitation->email,
            'status' => 'PENDING',
            'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
        ];
    }
}
