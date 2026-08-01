<?php

declare(strict_types=1);

namespace Sova\Identity\Domain\Token;

enum OneTimeTokenPurpose: string
{
    case PasswordReset = 'PASSWORD_RESET';
    case EmailVerification = 'EMAIL_VERIFICATION';
}
