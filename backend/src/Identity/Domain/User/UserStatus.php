<?php

declare(strict_types=1);

namespace Sova\Identity\Domain\User;

enum UserStatus: string
{
    case PendingVerification = 'PENDING_VERIFICATION';
    case Active = 'ACTIVE';
    case Locked = 'LOCKED';
    case Disabled = 'DISABLED';
    case Expired = 'EXPIRED';
    case Deleted = 'DELETED';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PendingVerification => in_array(
                $target,
                [self::Active, self::Disabled, self::Expired],
                true,
            ),
            self::Active => in_array(
                $target,
                [self::Locked, self::Disabled, self::Deleted],
                true,
            ),
            self::Locked => in_array(
                $target,
                [self::Active, self::Disabled, self::Deleted],
                true,
            ),
            self::Disabled => in_array(
                $target,
                [self::Active, self::Deleted],
                true,
            ),
            self::Expired => $target === self::Deleted,
            self::Deleted => false,
        };
    }
}
