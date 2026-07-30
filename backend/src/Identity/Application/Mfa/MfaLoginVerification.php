<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

use DateTimeImmutable;

final readonly class MfaLoginVerification
{
    public function __construct(
        public bool $enabled,
        public ?DateTimeImmutable $verifiedAt,
        public int $recoveryCodesRemaining,
        public bool $usedRecoveryCode = false,
    ) {}

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }
}
