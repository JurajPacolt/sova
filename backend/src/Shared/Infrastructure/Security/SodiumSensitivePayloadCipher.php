<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Security;

use JsonException;
use RuntimeException;
use SensitiveParameter;
use SodiumException;
use Sova\Shared\Application\Security\EncryptedPayload;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class SodiumSensitivePayloadCipher implements SensitivePayloadCipher
{
    private string $keyId;
    private string $key;

    public function __construct(Settings $settings)
    {
        $keyId = $settings->string('security.sensitive_payload_key_id', '');
        $encodedKey = $settings->string('security.sensitive_payload_key', '');
        $key = base64_decode($encodedKey, true);

        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1) {
            throw new RuntimeException(
                'SENSITIVE_PAYLOAD_KEY_ID must use 1-64 safe identifier characters.',
            );
        }

        if (
            !is_string($key)
            || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ) {
            throw new RuntimeException(
                'SENSITIVE_PAYLOAD_KEY must be a base64-encoded 256-bit key.',
            );
        }

        $this->keyId = $keyId;
        $this->key = $key;
    }

    public function encrypt(
        #[SensitiveParameter]
        array $payload,
    ): EncryptedPayload {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $ciphertext = sodium_crypto_secretbox($plainText, $nonce, $this->key);

        return new EncryptedPayload(
            keyId: $this->keyId,
            ciphertext: $this->base64UrlEncode($nonce . $ciphertext),
        );
    }

    public function decrypt(
        string $keyId,
        #[SensitiveParameter]
        string $ciphertext,
    ): array {
        if (!hash_equals($this->keyId, $keyId)) {
            throw new RuntimeException('The sensitive payload encryption key is unavailable.');
        }

        $decoded = $this->base64UrlDecode($ciphertext);
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($decoded) <= $nonceLength) {
            throw new RuntimeException('The sensitive payload is invalid.');
        }

        $plainText = sodium_crypto_secretbox_open(
            substr($decoded, $nonceLength),
            substr($decoded, 0, $nonceLength),
            $this->key,
        );

        if (!is_string($plainText)) {
            throw new RuntimeException('The sensitive payload could not be authenticated.');
        }

        try {
            $decodedPayload = json_decode(
                $plainText,
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The sensitive payload is invalid.',
                previous: $exception,
            );
        } finally {
            sodium_memzero($plainText);
        }

        if (!is_array($decodedPayload)) {
            throw new RuntimeException('The sensitive payload must be an object.');
        }

        $payload = [];

        foreach ($decodedPayload as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException(
                    'The sensitive payload must contain only string fields.',
                );
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @throws SodiumException
     */
    private function base64UrlDecode(
        #[SensitiveParameter]
        string $value,
    ): string {
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', $padding),
            true,
        );

        if (!is_string($decoded)) {
            throw new RuntimeException('The sensitive payload encoding is invalid.');
        }

        return $decoded;
    }
}
