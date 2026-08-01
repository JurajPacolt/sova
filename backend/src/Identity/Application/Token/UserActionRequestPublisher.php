<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Token;

use DateTimeImmutable;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;

interface UserActionRequestPublisher
{
    public function publish(
        OneTimeTokenPurpose $purpose,
        string $normalizedEmail,
        DateTimeImmutable $expiresAt,
    ): void;
}
