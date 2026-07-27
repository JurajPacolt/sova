<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use DateTimeImmutable;
use Sova\Identity\Domain\Session\IssuedSessionToken;

final readonly class LoginResult
{
    public function __construct(
        public UserCredentials $user,
        public string $sessionId,
        public DateTimeImmutable $expiresAt,
        public IssuedSessionToken $sessionToken,
        public IssuedSessionToken $csrfToken,
    ) {}
}
