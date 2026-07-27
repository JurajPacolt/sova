<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Security;

use Sova\Identity\Domain\Token\OneTimeTokenPurpose;

interface PublicEmailRateLimiter
{
    public function consumeAllowance(
        OneTimeTokenPurpose $purpose,
        string $normalizedEmail,
        string $ipAddress,
    ): bool;
}
