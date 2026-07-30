<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

final readonly class MfaConfirmation
{
    /**
     * @param list<string> $recoveryCodes
     */
    public function __construct(
        public array $recoveryCodes,
    ) {}
}
