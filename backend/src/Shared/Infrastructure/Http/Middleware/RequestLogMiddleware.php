<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One structured line per request: what was asked, what came back, how long it
 * took.
 *
 * This is the whole of SOVA's metrics story for now, and deliberately so. Real
 * counters in PHP-FPM need a shared store (APCu, Redis) because a worker's
 * memory dies with the request — that is an infrastructure decision, not a code
 * one, and inventing it here would produce numbers nobody scrapes. A structured
 * access log gives the same three signals (traffic, error rate, latency) out of
 * something every deployment already collects.
 *
 * Two fields exist purely so the log can be aggregated:
 *
 * - `route` is the path with identifiers replaced by `{id}`, so a thousand issue
 *   detail requests group into one row instead of a thousand. The raw `path`
 *   stays alongside it, because the person chasing one failed request needs the
 *   actual one.
 * - `tenant_id` is lifted out of the path. It is already there in plain sight;
 *   naming it as a field is the difference between "grep the URL" and "alert on
 *   one tenant's error rate".
 *
 * A failure is logged here as an ordinary line with its status, not as an error.
 * `ApiErrorMiddleware` already reports the fault with its exception and problem
 * code; two error lines for one event make an alert fire twice and a reader
 * wonder whether two things went wrong.
 */
final readonly class RequestLogMiddleware implements MiddlewareInterface
{
    /** Hit constantly by a scheduler and says nothing. */
    private const array SILENT_PATHS = ['/api/v1/health', '/api/v1/health/live'];

    private const string UUID =
        '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private LoggerInterface $logger) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $startedAt = hrtime(true);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $exception) {
            // Nothing turned this into a response, so the request still gets its
            // line — a request that vanishes from the log is the one somebody
            // will spend an afternoon looking for.
            $this->write($request, 0, $startedAt);

            throw $exception;
        }

        $this->write($request, $response->getStatusCode(), $startedAt);

        return $response;
    }

    private function write(ServerRequestInterface $request, int $status, int $startedAt): void
    {
        $path = $request->getUri()->getPath();

        if (in_array($path, self::SILENT_PATHS, true)) {
            return;
        }

        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        $this->logger->info('http_request', [
            'method' => $request->getMethod(),
            'path' => $path,
            'route' => $this->template($path),
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
            'request_id' => is_string($requestId) ? $requestId : '',
            'tenant_id' => $this->tenantId($path),
        ]);
    }

    /** The path with identifiers folded away, so requests group by shape. */
    private function template(string $path): string
    {
        $folded = preg_replace('/\/' . self::UUID . '(?=\/|$)/i', '/{id}', $path) ?? $path;

        // Issue keys travel in the path as well, and `SOVA-1` is as much an
        // identifier as a UUID is.
        return preg_replace('/\/[A-Z][A-Z0-9]*-\d+(?=\/|$)/', '/{key}', $folded) ?? $folded;
    }

    private function tenantId(string $path): ?string
    {
        $matched = preg_match('/\/tenants\/(' . self::UUID . ')(?=\/|$)/i', $path, $matches);

        return $matched === 1 ? $matches[1] : null;
    }
}
