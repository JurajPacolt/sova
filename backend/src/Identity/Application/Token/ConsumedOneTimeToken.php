<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Token;

use Sova\Identity\Domain\Token\OneTimeTokenPurpose;

final readonly class ConsumedOneTimeToken
{
    public function __construct(
        public string $id,
        public string $userId,
        public OneTimeTokenPurpose $purpose,
    ) {}
}
