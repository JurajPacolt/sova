<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sova\Shared\Domain\ValueObject\UuidV7;

final class UuidV7Test extends TestCase
{
    public function testGeneratedIdentifierContainsTheTimestampAndRfcBits(): void
    {
        $timestamp = 1_785_065_678_901;
        $identifier = UuidV7::generate($timestamp);
        $value = (string) $identifier;

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value,
        );
        self::assertSame($timestamp, $identifier->unixMilliseconds());
        self::assertTrue($identifier->equals(UuidV7::fromString(strtoupper($value))));
    }

    public function testIdentifiersSortByGenerationMillisecond(): void
    {
        $earlier = (string) UuidV7::generate(1_785_065_678_901);
        $later = (string) UuidV7::generate(1_785_065_678_902);

        self::assertLessThan($later, $earlier);
    }

    public function testInvalidIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UuidV7::fromString('not-a-uuid');
    }
}
