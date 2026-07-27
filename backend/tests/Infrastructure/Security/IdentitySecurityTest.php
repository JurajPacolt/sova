<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Application\Security\SessionTokenGenerator;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;

final class IdentitySecurityTest extends TestCase
{
    public function testPasswordHasherUsesArgon2idAndDetectsChangedCosts(): void
    {
        $hasher = new Argon2idPasswordHasher(
            memoryCost: 8192,
            timeCost: 1,
            threads: 1,
        );
        $hash = $hasher->hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
        self::assertFalse($hasher->needsRehash($hash));

        $strongerHasher = new Argon2idPasswordHasher(
            memoryCost: 8192,
            timeCost: 2,
            threads: 1,
        );

        self::assertTrue($strongerHasher->needsRehash($hash));
    }

    public function testSessionTokensAreRandomAndStoredOnlyAsHashes(): void
    {
        $generator = new SessionTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]{43}$/',
            $first->plainText(),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->hash());
        self::assertNotSame($first->plainText(), $first->hash());
        self::assertNotSame($first->plainText(), $second->plainText());
        self::assertTrue($generator->matches($first->plainText(), $first->hash()));
        self::assertFalse($generator->matches($second->plainText(), $first->hash()));
    }

    public function testOneTimeTokensHaveAtLeast256BitsOfEntropyAndOnlyExposeAHashForStorage(): void
    {
        $generator = new OneTimeTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]{43}$/',
            $first->plainText(),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->hash());
        self::assertNotSame($first->plainText(), $first->hash());
        self::assertNotSame($first->plainText(), $second->plainText());
        self::assertTrue($generator->hasValidFormat($first->plainText()));
        self::assertFalse($generator->hasValidFormat('predictable-token'));
        self::assertSame($first->hash(), $generator->hash($first->plainText()));
    }
}
