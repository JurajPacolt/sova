<?php

declare(strict_types=1);

namespace Sova\Tenancy\Domain\Membership;

enum MembershipStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
    case Removed = 'REMOVED';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Active => in_array(
                $target,
                [self::Disabled, self::Removed],
                true,
            ),
            self::Disabled => in_array(
                $target,
                [self::Active, self::Removed],
                true,
            ),
            self::Removed => false,
        };
    }
}
