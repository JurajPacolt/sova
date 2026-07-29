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
        // The payload is base64url and its final character carries only the top
        // bits of the last byte; the rest is padding. Editing that character
        // therefore often decodes to identical bytes — not tampering at all —
        // which made this test fail about a quarter of the time, depending on
        // the random nonce. Every other position carries a full six significant
        // bits, so a change there always alters the bytes the MAC covers.
        $position = intdiv(strlen($encrypted->ciphertext), 2);
        $tampered = substr_replace(
            $encrypted->ciphertext,
            $encrypted->ciphertext[$position] === 'A' ? 'B' : 'A',
            $position,
            1,
        );

        self::assertNotSame($encrypted->ciphertext, $tampered);
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
