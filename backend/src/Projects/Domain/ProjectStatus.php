<?php

declare(strict_types=1);

namespace Sova\Projects\Domain;

enum ProjectStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Active => $target === self::Archived,
            self::Archived => $target === self::Active,
        };
    }
}
