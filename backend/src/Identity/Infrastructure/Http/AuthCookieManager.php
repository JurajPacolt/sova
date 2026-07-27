<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class AuthCookieManager
{
    private string $sessionCookieName;
    private string $csrfCookieName;
    private bool $secure;
    private string $sameSite;

    public function __construct(Settings $settings)
    {
        $this->sessionCookieName = $settings->string('auth.session_cookie_name');
        $this->csrfCookieName = $settings->string('auth.csrf_cookie_name');
        $this->secure = $settings->bool('auth.cookie_secure', true);
        $this->sameSite = $settings->string('auth.cookie_same_site', 'Lax');

        if (!in_array($this->sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new InvalidArgumentException(
                'AUTH_COOKIE_SAME_SITE must be Strict, Lax, or None.',
            );
        }

        if ($this->sameSite === 'None' && !$this->secure) {
            throw new InvalidArgumentException(
                'SameSite=None authentication cookies must be Secure.',
            );
        }
    }

    public function withAuthenticationCookies(
        ResponseInterface $response,
        #[SensitiveParameter]
        string $sessionToken,
        #[SensitiveParameter]
        string $csrfToken,
        DateTimeImmutable $expiresAt,
    ): ResponseInterface {
        return $response
            ->withAddedHeader(
                'Set-Cookie',
                $this->cookie(
                    $this->sessionCookieName,
                    $sessionToken,
                    $expiresAt,
                    httpOnly: true,
                ),
            )
            ->withAddedHeader(
                'Set-Cookie',
                $this->cookie(
                    $this->csrfCookieName,
                    $csrfToken,
                    $expiresAt,
                    httpOnly: false,
                ),
            );
    }

    public function clearAuthenticationCookies(
        ResponseInterface $response,
    ): ResponseInterface {
        $expired = new DateTimeImmutable('@1');

        return $response
            ->withAddedHeader(
                'Set-Cookie',
                $this->cookie(
                    $this->sessionCookieName,
                    '',
                    $expired,
                    httpOnly: true,
                    maxAge: 0,
                ),
            )
            ->withAddedHeader(
                'Set-Cookie',
                $this->cookie(
                    $this->csrfCookieName,
                    '',
                    $expired,
                    httpOnly: false,
                    maxAge: 0,
                ),
            );
    }

    public function sessionCookieName(): string
    {
        return $this->sessionCookieName;
    }

    private function cookie(
        string $name,
        #[SensitiveParameter]
        string $value,
        DateTimeImmutable $expiresAt,
        bool $httpOnly,
        ?int $maxAge = null,
    ): string {
        $maxAge ??= max(0, $expiresAt->getTimestamp() - time());
        $parts = [
            sprintf('%s=%s', $name, rawurlencode($value)),
            'Path=/',
            sprintf('Expires=%s', $expiresAt->format('D, d M Y H:i:s \G\M\T')),
            sprintf('Max-Age=%d', $maxAge),
            sprintf('SameSite=%s', $this->sameSite),
        ];

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        return implode('; ', $parts);
    }
}
