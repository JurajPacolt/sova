<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http;

use Sova\Identity\Application\Impersonation\ImpersonationDetails;

final readonly class ImpersonationSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(ImpersonationDetails $impersonation): array
    {
        return [
            'id' => $impersonation->id,
            'status' => $impersonation->status->value,
            'actor' => [
                'id' => $impersonation->actorUserId,
                'email' => $impersonation->actorEmail,
                'display_name' => $impersonation->actorDisplayName,
            ],
            'effective_user' => [
                'id' => $impersonation->effectiveUserId,
                'email' => $impersonation->effectiveUserEmail,
                'display_name' => $impersonation->effectiveUserDisplayName,
            ],
            'tenant' => [
                'id' => $impersonation->tenantId,
                'name' => $impersonation->tenantName,
                'slug' => $impersonation->tenantSlug,
            ],
            'reason' => $impersonation->reason,
            'reauthenticated_at' => $impersonation->reauthenticatedAt->format(
                DATE_ATOM,
            ),
            'started_at' => $impersonation->startedAt->format(DATE_ATOM),
            'expires_at' => $impersonation->expiresAt->format(DATE_ATOM),
        ];
    }
}
