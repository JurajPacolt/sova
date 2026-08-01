<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http;

use Sova\Identity\Application\System\SystemUserDetails;

final class SystemUserSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(SystemUserDetails $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->displayName,
            'status' => $user->status->value,
            'preferred_locale' => $user->preferredLocale,
            'email_verified_at' => $user->emailVerifiedAt?->format(DATE_ATOM),
            'failed_login_count' => $user->failedLoginCount,
            'locked_until' => $user->lockedUntil?->format(DATE_ATOM),
            'is_superadmin' => $user->isSuperadmin,
            'created_at' => $user->createdAt->format(DATE_ATOM),
            'updated_at' => $user->updatedAt->format(DATE_ATOM),
        ];
    }
}
