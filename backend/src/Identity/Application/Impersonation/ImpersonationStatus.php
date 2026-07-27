<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

enum ImpersonationStatus: string
{
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Invalidated = 'INVALIDATED';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
