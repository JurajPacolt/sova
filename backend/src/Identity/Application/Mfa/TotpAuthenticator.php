<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

final class TotpAuthenticator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS = 6;
    private const PERIOD_SECONDS = 30;
    private const WINDOW = 1;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function verify(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
        DateTimeImmutable $now,
        ?int $lastUsedStep = null,
    ): ?int {
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return null;
        }

        $currentStep = intdiv($now->getTimestamp(), self::PERIOD_SECONDS);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $currentStep + $offset;

            if ($step < 0 || ($lastUsedStep !== null && $step <= $lastUsedStep)) {
                continue;
            }

            if (hash_equals($this->codeAtStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function codeAt(
        #[SensitiveParameter]
        string $secret,
        DateTimeImmutable $at,
    ): string {
        return $this->codeAtStep(
            $secret,
            intdiv($at->getTimestamp(), self::PERIOD_SECONDS),
        );
    }

    public function provisioningUri(
        #[SensitiveParameter]
        string $secret,
        string $email,
        string $issuer,
    ): string {
        $label = rawurlencode($issuer . ':' . $email);
        $query = http_build_query(
            [
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD_SECONDS,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    private function codeAtStep(
        #[SensitiveParameter]
        string $secret,
        int $step,
    ): string {
        if ($step < 0) {
            throw new InvalidArgumentException('A TOTP step cannot be negative.');
        }

        $binarySecret = $this->base32Decode($secret);
        $counter = pack('N2', intdiv($step, 4_294_967_296), $step % 4_294_967_296);
        $digest = hash_hmac('sha1', $counter, $binarySecret, true);
        $offset = ord($digest[19]) & 0x0f;
        $binary = (
            ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff)
        );

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function base32Encode(
        #[SensitiveParameter]
        string $value,
    ): string {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($value[$index]);
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }

        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function base32Decode(
        #[SensitiveParameter]
        string $value,
    ): string {
        $normalized = strtoupper(rtrim($value, '='));

        if (
            $normalized === ''
            || preg_match('/^[A-Z2-7]+$/D', $normalized) !== 1
        ) {
            throw new InvalidArgumentException('The TOTP secret is invalid.');
        }

        $decoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($normalized) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position === false) {
                throw new InvalidArgumentException('The TOTP secret is invalid.');
            }

            $buffer = ($buffer << 5) | $position;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $decoded;
    }
}
