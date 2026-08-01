<?php

declare(strict_types=1);

namespace Sova\Identity\Domain\Token;

final readonly class IssuedOneTimeToken
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
