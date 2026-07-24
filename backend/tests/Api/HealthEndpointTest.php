<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class HealthEndpointTest extends TestCase
{
    /**
     * @var App<Container>
     */
    private App $app;

    protected function setUp(): void
    {
        $this->app = ApplicationFactory::create(dirname(__DIR__, 2));
    }

    public function testApiInfoIsAvailable(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = $this->decodeResponse($response->getBody()->__toString());

        self::assertSame('SOVA API', $payload['name']);
        self::assertSame('v1', $payload['api_version']);
    }

    public function testLivenessEndpointIsAvailable(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/health/live',
        );
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/',
            $response->getHeaderLine('X-Request-ID'),
        );

        $payload = $this->decodeResponse($response->getBody()->__toString());

        self::assertSame('ok', $payload['status']);
    }

    public function testUnknownRouteUsesProblemDetails(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/unknown');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/problem+json',
            $response->getHeaderLine('Content-Type'),
        );

        $payload = $this->decodeResponse($response->getBody()->__toString());

        self::assertSame(404, $payload['status']);
        self::assertSame(
            $response->getHeaderLine('X-Request-ID'),
            $payload['request_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('Expected a JSON object response.');
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                self::fail('Expected JSON object keys to be strings.');
            }

            $payload[$key] = $value;
        }

        return $payload;
    }
}
