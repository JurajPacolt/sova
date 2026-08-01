<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

final readonly class MfaSessionStatus
{
    public function __construct(
        public bool $enabled,
        public bool $verified,
        public bool $enrollmentRequired,
        public int $recoveryCodesRemaining,
    ) {}
}
