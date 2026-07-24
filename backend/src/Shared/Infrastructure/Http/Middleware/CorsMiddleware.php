<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private Settings $settings,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return $handler->handle($request);
        }

        $allowedOrigins = $this->settings->stringList('cors.allowed_origins');

        if (!in_array($origin, $allowedOrigins, true)) {
            return $this->responseFactory
                ->createResponse(403)
                ->withHeader('Content-Type', 'application/problem+json; charset=utf-8')
                ->withHeader('Vary', 'Origin');
        }

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = $this->responseFactory->createResponse(204);
        } else {
            $response = $handler->handle($request);
        }

        $allowedMethods = $this->settings->stringList('cors.allowed_methods');
        $allowedHeaders = $this->settings->stringList('cors.allowed_headers');
        $exposedHeaders = $this->settings->stringList('cors.exposed_headers');

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $allowedHeaders))
            ->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->settings->int('cors.max_age', 600))
            ->withHeader('Vary', 'Origin');
    }
}
