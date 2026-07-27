<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

interface LoginRateLimiter
{
    public function isLimited(string $normalizedEmail, string $ipAddress): bool;

    public function recordFailure(string $normalizedEmail, string $ipAddress): void;

    public function resetAccount(string $normalizedEmail): void;
}
