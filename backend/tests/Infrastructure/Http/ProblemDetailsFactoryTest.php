<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Http\ProblemDetailsFactory;

final class ProblemDetailsFactoryTest extends TestCase
{
    #[DataProvider('problemTypeProvider')]
    public function testEveryDomainProblemTypeHasAStableHttpMapping(
        ProblemType $problemType,
        int $expectedStatus,
    ): void {
        $factory = $this->factory(debug: false);
        $problem = $factory->fromThrowable(
            new DomainProblemException(
                $problemType,
                'TEST_PROBLEM',
                'A safe domain detail.',
            ),
            '/api/v1/test',
            'request-1234',
        );

        self::assertSame(
            sprintf('urn:sova:problem:%s', $problemType->value),
            $problem->type,
        );
        self::assertSame($expectedStatus, $problem->status);
        self::assertSame('TEST_PROBLEM', $problem->code);
        self::assertSame('A safe domain detail.', $problem->detail);
    }

    public function testValidationProblemIncludesFieldErrors(): void
    {
        $factory = $this->factory(debug: false);
        $problem = $factory->fromThrowable(
            new DomainProblemException(
                ProblemType::ValidationFailed,
                'PROJECT_INPUT_INVALID',
                'The project input is invalid.',
                [
                    'name' => ['Enter a project name.'],
                    'code' => ['Use two to ten uppercase characters.'],
                ],
            ),
            '/api/v1/projects',
            'request-1234',
        );

        self::assertSame([
            'name' => ['Enter a project name.'],
            'code' => ['Use two to ten uppercase characters.'],
        ], $problem->toArray()['errors']);
    }

    public function testSlimNotFoundUsesTheStableResourceProblem(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/missing',
        );
        $factory = $this->factory(debug: false);
        $problem = $factory->fromThrowable(
            new HttpNotFoundException($request),
            $request->getUri()->getPath(),
            'request-1234',
        );

        self::assertSame(404, $problem->status);
        self::assertSame('urn:sova:problem:resource-not-found', $problem->type);
        self::assertSame('RESOURCE_NOT_FOUND', $problem->code);
        self::assertSame('Resource Not Found', $problem->title);
    }

    public function testUnexpectedExceptionDetailIsHiddenInProduction(): void
    {
        $factory = $this->factory(debug: false);
        $problem = $factory->fromThrowable(
            new RuntimeException('Database password must stay private.'),
            '/api/v1/test',
            'request-1234',
        );

        self::assertSame(500, $problem->status);
        self::assertSame('INTERNAL_SERVER_ERROR', $problem->code);
        self::assertSame('The server could not complete the request.', $problem->detail);
        self::assertStringNotContainsString('password', $problem->detail);
    }

    public function testUnexpectedExceptionDetailIsVisibleInDebugMode(): void
    {
        $factory = $this->factory(debug: true);
        $problem = $factory->fromThrowable(
            new RuntimeException('Debug detail.'),
            '/api/v1/test',
            'request-1234',
        );

        self::assertSame('Debug detail.', $problem->detail);
    }

    public function testDomainProblemRejectsAnUnstableCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainProblemException(
            ProblemType::Conflict,
            'invalid-code',
            'A safe detail.',
        );
    }

    /**
     * @return iterable<string, array{ProblemType, int}>
     */
    public static function problemTypeProvider(): iterable
    {
        yield 'invalid request' => [ProblemType::InvalidRequest, 400];
        yield 'authentication required' => [ProblemType::AuthenticationRequired, 401];
        yield 'permission denied' => [ProblemType::PermissionDenied, 403];
        yield 'resource not found' => [ProblemType::ResourceNotFound, 404];
        yield 'method not allowed' => [ProblemType::MethodNotAllowed, 405];
        yield 'not acceptable' => [ProblemType::NotAcceptable, 406];
        yield 'conflict' => [ProblemType::Conflict, 409];
        yield 'gone' => [ProblemType::Gone, 410];
        yield 'payload too large' => [ProblemType::PayloadTooLarge, 413];
        yield 'unsupported media type' => [ProblemType::UnsupportedMediaType, 415];
        yield 'validation failed' => [ProblemType::ValidationFailed, 422];
        yield 'rate limit exceeded' => [ProblemType::RateLimitExceeded, 429];
        yield 'internal server error' => [ProblemType::InternalServerError, 500];
        yield 'service unavailable' => [ProblemType::ServiceUnavailable, 503];
    }

    private function factory(bool $debug): ProblemDetailsFactory
    {
        return new ProblemDetailsFactory(new Settings([
            'app' => [
                'debug' => $debug,
            ],
        ]));
    }
}
