<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            )
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-site')
            ->withHeader(
                'Permissions-Policy',
                'camera=(), display-capture=(), geolocation=(), microphone=(), payment=()',
            )
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');

        if (strtolower($request->getUri()->getScheme()) === 'https') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
