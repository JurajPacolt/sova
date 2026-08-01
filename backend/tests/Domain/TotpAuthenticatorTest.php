<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sova\Identity\Application\Mfa\TotpAuthenticator;

final class TotpAuthenticatorTest extends TestCase
{
    private const RFC_SHA1_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testGeneratesTheSixDigitRfc6238Code(): void
    {
        $authenticator = new TotpAuthenticator();
        $at = (new DateTimeImmutable('@59'))->setTimezone(
            new DateTimeZone('UTC'),
        );

        self::assertSame(
            '287082',
            $authenticator->codeAt(self::RFC_SHA1_SECRET, $at),
        );
        self::assertSame(
            1,
            $authenticator->verify(
                self::RFC_SHA1_SECRET,
                '287082',
                $at,
            ),
        );
    }

    public function testAcceptsOnlyTheConfiguredClockWindowAndRejectsReplay(): void
    {
        $authenticator = new TotpAuthenticator();
        $now = (new DateTimeImmutable('@1700000000'))->setTimezone(
            new DateTimeZone('UTC'),
        );
        $previous = $now->modify('-30 seconds');
        $outside = $now->modify('-60 seconds');
        $previousCode = $authenticator->codeAt(
            self::RFC_SHA1_SECRET,
            $previous,
        );
        $previousStep = intdiv($previous->getTimestamp(), 30);

        self::assertSame(
            $previousStep,
            $authenticator->verify(
                self::RFC_SHA1_SECRET,
                $previousCode,
                $now,
            ),
        );
        self::assertNull($authenticator->verify(
            self::RFC_SHA1_SECRET,
            $previousCode,
            $now,
            $previousStep,
        ));
        self::assertNull($authenticator->verify(
            self::RFC_SHA1_SECRET,
            $authenticator->codeAt(self::RFC_SHA1_SECRET, $outside),
            $now,
        ));
        self::assertNull($authenticator->verify(
            self::RFC_SHA1_SECRET,
            '12345a',
            $now,
        ));
    }

    public function testGeneratedSecretCanBeProvisionedAndVerified(): void
    {
        $authenticator = new TotpAuthenticator();
        $secret = $authenticator->generateSecret();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $code = $authenticator->codeAt($secret, $now);
        $uri = $authenticator->provisioningUri(
            $secret,
            'member@example.test',
            'SOVA',
        );

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $secret);
        self::assertNotNull($authenticator->verify($secret, $code, $now));
        self::assertStringStartsWith(
            'otpauth://totp/SOVA%3Amember%40example.test?',
            $uri,
        );
        self::assertStringContainsString('secret=' . $secret, $uri);
        self::assertStringContainsString('issuer=SOVA', $uri);
    }
}
