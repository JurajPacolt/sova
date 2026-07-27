<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Security;

interface SensitivePayloadCipher
{
    /**
     * @param array<string, string> $payload
     */
    public function encrypt(array $payload): EncryptedPayload;

    /**
     * @return array<string, string>
     */
    public function decrypt(string $keyId, string $ciphertext): array;
}
