<?php

declare(strict_types=1);

namespace Sova\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class OpenApiContractTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const HTTP_METHODS = [
        'delete',
        'get',
        'head',
        'options',
        'patch',
        'post',
        'put',
        'trace',
    ];

    public function testContractDescribesEveryRegisteredApiOperation(): void
    {
        $document = $this->decodeDocument();

        self::assertSame('3.1.0', $document['openapi'] ?? null);
        self::assertSame(
            'https://spec.openapis.org/oas/3.1/dialect/base',
            $document['jsonSchemaDialect'] ?? null,
        );

        $documentedOperations = $this->extractDocumentedOperations($document);
        $registeredOperations = [];
        $app = ApplicationFactory::create(dirname(__DIR__, 2));

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $methods = array_map(
                static fn(string $method): string => strtolower($method),
                $route->getMethods(),
            );
            sort($methods);
            $registeredOperations[$route->getPattern()] = $methods;
        }

        ksort($documentedOperations);
        ksort($registeredOperations);

        self::assertSame($registeredOperations, $documentedOperations);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDocument(): array
    {
        $path = dirname(__DIR__, 3) . '/docs/openapi.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            self::fail(sprintf('Unable to read OpenAPI document at "%s".', $path));
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('The OpenAPI document must contain a JSON object.');
        }

        $document = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                self::fail('OpenAPI object keys must be strings.');
            }

            $document[$key] = $value;
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, list<string>>
     */
    private function extractDocumentedOperations(array $document): array
    {
        $paths = $document['paths'] ?? null;

        if (!is_array($paths)) {
            self::fail('The OpenAPI document must define a paths object.');
        }

        $operations = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                self::fail('Every OpenAPI path item must be an object with a string key.');
            }

            $methods = [];

            foreach (self::HTTP_METHODS as $method) {
                if (array_key_exists($method, $pathItem)) {
                    $methods[] = $method;
                }
            }

            sort($methods);
            $operations[$path] = $methods;
        }

        return $operations;
    }
}
