<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Http;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Sova\Identity\Infrastructure\Http\AuthCookieManager;
use Sova\Shared\Infrastructure\Configuration\Settings;

final class AuthCookieManagerTest extends TestCase
{
    public function testSessionCookieIsHttpOnlyAndCsrfCookieIsReadable(): void
    {
        $manager = new AuthCookieManager(new Settings([
            'auth' => [
                'session_cookie_name' => 'sova_session',
                'csrf_cookie_name' => 'sova_csrf',
                'cookie_secure' => true,
                'cookie_same_site' => 'Lax',
            ],
        ]));
        $response = $manager->withAuthenticationCookies(
            (new ResponseFactory())->createResponse(),
            'session-token',
            'csrf-token',
            new DateTimeImmutable('2099-01-01T00:00:00+00:00'),
        );
        $headers = $response->getHeader('Set-Cookie');

        self::assertCount(2, $headers);
        self::assertStringContainsString('sova_session=session-token', $headers[0]);
        self::assertStringContainsString('HttpOnly', $headers[0]);
        self::assertStringContainsString('Secure', $headers[0]);
        self::assertStringContainsString('SameSite=Lax', $headers[0]);
        self::assertStringContainsString('sova_csrf=csrf-token', $headers[1]);
        self::assertStringNotContainsString('HttpOnly', $headers[1]);
        self::assertStringContainsString('Secure', $headers[1]);
    }
}
