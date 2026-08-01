<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Http;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestLogMiddleware;

final class RequestLogMiddlewareTest extends TestCase
{
    private const string TENANT_ID = '019f9f00-0000-7000-8000-000000000001';

    /**
     * @return array<string, mixed>|null
     */
    private function log(string $path, int $status): ?array
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://sova.test' . $path)
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-1');

        (new RequestLogMiddleware($logger))->process($request, $this->respondWith($status));

        $records = $handler->getRecords();

        if ($records === []) {
            return null;
        }

        /** @var array<string, mixed> $context */
        $context = $records[0]->context;

        return $context;
    }

    private function respondWith(int $status): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public function __construct(private int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus($this->status);
            }
        };
    }

    /**
     * The identifiers are folded away so a thousand issue requests aggregate
     * into one row, and the raw path stays for the person chasing one of them.
     */
    public function testTheLineCarriesBothTheShapeAndTheActualRequest(): void
    {
        $path = sprintf('/api/v1/tenants/%s/issues/SOVA-1', self::TENANT_ID);
        $context = $this->log($path, 200);

        self::assertNotNull($context);
        self::assertSame('/api/v1/tenants/{id}/issues/{key}', $context['route']);
        self::assertSame($path, $context['path']);
        self::assertSame(self::TENANT_ID, $context['tenant_id']);
        self::assertSame(200, $context['status']);
        self::assertSame('req-1', $context['request_id']);
        self::assertIsFloat($context['duration_ms']);
    }

    /**
     * `ApiErrorMiddleware` already reports the fault; a second error line would
     * make one event look like two.
     */
    public function testARefusalIsStillOneOrdinaryLine(): void
    {
        $context = $this->log(sprintf('/api/v1/tenants/%s/issues/search', self::TENANT_ID), 422);

        self::assertNotNull($context);
        self::assertSame('/api/v1/tenants/{id}/issues/search', $context['route']);
        self::assertSame(422, $context['status']);
    }

    public function testASystemRequestCarriesNoTenant(): void
    {
        $context = $this->log('/api/v1/system/tenants', 200);

        self::assertNotNull($context);
        self::assertNull($context['tenant_id']);
    }

    /** A probe every second would bury everything else in the stream. */
    public function testTheLivenessProbeIsNotLogged(): void
    {
        self::assertNull($this->log('/api/v1/health/live', 200));
        self::assertNotNull($this->log('/api/v1/health/ready', 503));
    }
}
