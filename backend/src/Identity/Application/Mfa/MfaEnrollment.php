<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

final readonly class MfaEnrollment
{
    public function __construct(
        public string $secret,
        public string $otpauthUri,
    ) {}
}
