<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Token;

use DateTimeImmutable;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;

interface OneTimeTokenRepository
{
    public function replaceActive(
        string $tokenId,
        string $userId,
        OneTimeTokenPurpose $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): void;

    public function consumeActive(
        string $tokenHash,
        OneTimeTokenPurpose $purpose,
    ): ?ConsumedOneTimeToken;

    public function findConsumed(
        string $tokenHash,
        OneTimeTokenPurpose $purpose,
    ): ?ConsumedOneTimeToken;
}
