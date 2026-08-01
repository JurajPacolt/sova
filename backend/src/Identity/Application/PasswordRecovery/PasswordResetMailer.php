<?php

declare(strict_types=1);

namespace Sova\Identity\Application\PasswordRecovery;

use DateTimeImmutable;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Domain\Token\IssuedOneTimeToken;

interface PasswordResetMailer
{
    public function send(
        UserCredentials $user,
        IssuedOneTimeToken $token,
        DateTimeImmutable $expiresAt,
    ): void;
}
