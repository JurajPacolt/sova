<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Security;

final readonly class EncryptedPayload
{
    public function __construct(
        public string $keyId,
        public string $ciphertext,
    ) {}
}
