<?php

declare(strict_types=1);

namespace Sova\Shared\Domain\ValueObject;

use InvalidArgumentException;
use Stringable;

final readonly class UuidV7 implements Stringable
{
    private const MAX_UNIX_MILLISECONDS = 281_474_976_710_655;

    private function __construct(private string $value) {}

    public static function generate(?int $unixMilliseconds = null): self
    {
        $unixMilliseconds ??= (int) floor(microtime(true) * 1000);

        if (
            $unixMilliseconds < 0
            || $unixMilliseconds > self::MAX_UNIX_MILLISECONDS
        ) {
            throw new InvalidArgumentException(
                'UUIDv7 timestamp must fit into 48 bits.',
            );
        }

        $bytes = random_bytes(16);
        $timestamp = $unixMilliseconds;

        for ($index = 5; $index >= 0; --$index) {
            $bytes[$index] = chr($timestamp & 0xff);
            $timestamp >>= 8;
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower($value);

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException('The value must be a valid UUIDv7.');
        }

        return new self($normalized);
    }

    public function unixMilliseconds(): int
    {
        return (int) hexdec(str_replace('-', '', substr($this->value, 0, 13)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
