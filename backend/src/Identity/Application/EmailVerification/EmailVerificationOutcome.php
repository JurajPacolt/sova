<?php

declare(strict_types=1);

namespace Sova\Identity\Application\EmailVerification;

enum EmailVerificationOutcome: string
{
    case Verified = 'VERIFIED';
    case AlreadyVerified = 'ALREADY_VERIFIED';
}
