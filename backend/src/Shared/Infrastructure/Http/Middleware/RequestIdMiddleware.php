<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'request_id';
    public const HEADER = 'X-Request-ID';

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $requestId = $this->resolveRequestId($request);
        $request = $request->withAttribute(self::ATTRIBUTE, $requestId);

        return $handler->handle($request)->withHeader(self::HEADER, $requestId);
    }

    private function resolveRequestId(ServerRequestInterface $request): string
    {
        $candidate = trim($request->getHeaderLine(self::HEADER));

        if (
            $candidate !== ''
            && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $candidate) === 1
        ) {
            return $candidate;
        }

        return bin2hex(random_bytes(16));
    }
}
