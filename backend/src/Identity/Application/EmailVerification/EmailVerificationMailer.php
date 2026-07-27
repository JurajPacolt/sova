<?php

declare(strict_types=1);

namespace Sova\Identity\Application\EmailVerification;

use DateTimeImmutable;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Domain\Token\IssuedOneTimeToken;

interface EmailVerificationMailer
{
    public function send(
        UserCredentials $user,
        IssuedOneTimeToken $token,
        DateTimeImmutable $expiresAt,
    ): void;
}
