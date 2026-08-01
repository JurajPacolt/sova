<?php

declare(strict_types=1);

namespace Sova\Identity\Domain\Session;

final readonly class IssuedSessionToken
{
    public function __construct(
        private string $plainText,
        private string $hash,
    ) {}

    public function plainText(): string
    {
        return $this->plainText;
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
