<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Security\SodiumSensitivePayloadCipher;

final class SensitivePayloadCipherTest extends TestCase
{
    public function testSensitivePayloadIsAuthenticatedAndDoesNotExposePlainText(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt([
            'normalized_email' => 'member@example.test',
        ]);

        self::assertSame('test-v1', $encrypted->keyId);
        self::assertStringNotContainsString(
            'member@example.test',
            $encrypted->ciphertext,
        );
        self::assertSame(
            ['normalized_email' => 'member@example.test'],
            $cipher->decrypt($encrypted->keyId, $encrypted->ciphertext),
        );
    }

    public function testTamperedSensitivePayloadIsRejected(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt([
            'normalized_email' => 'member@example.test',
        ]);
        $lastCharacter = substr($encrypted->ciphertext, -1);
        $tampered = substr($encrypted->ciphertext, 0, -1)
            . ($lastCharacter === 'A' ? 'B' : 'A');

        $this->expectException(RuntimeException::class);

        $cipher->decrypt($encrypted->keyId, $tampered);
    }

    private function cipher(): SodiumSensitivePayloadCipher
    {
        return new SodiumSensitivePayloadCipher(new Settings([
            'security' => [
                'sensitive_payload_key_id' => 'test-v1',
                'sensitive_payload_key' => base64_encode(str_repeat('0', 32)),
            ],
        ]));
    }
}
