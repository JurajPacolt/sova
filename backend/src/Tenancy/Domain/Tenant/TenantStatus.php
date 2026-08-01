<?php

declare(strict_types=1);

namespace Sova\Tenancy\Domain\Tenant;

enum TenantStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Archived = 'ARCHIVED';
    case DeletionPending = 'DELETION_PENDING';
    case Deleted = 'DELETED';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Active,
            self::Active => in_array(
                $target,
                [self::Suspended, self::Archived],
                true,
            ),
            self::Suspended => in_array(
                $target,
                [self::Active, self::Archived],
                true,
            ),
            self::Archived => $target === self::DeletionPending,
            self::DeletionPending => in_array(
                $target,
                [self::Archived, self::Deleted],
                true,
            ),
            self::Deleted => false,
        };
    }
}
