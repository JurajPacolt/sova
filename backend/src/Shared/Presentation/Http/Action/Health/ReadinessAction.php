<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http\Action\Health;

use Doctrine\DBAL\Connection;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Throwable;

final readonly class ReadinessAction
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $args
     *
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return JsonResponse::write($response, [
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                ],
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Readiness check failed.', [
                'exception' => $exception,
                'request_id' => $request->getAttribute(RequestIdMiddleware::ATTRIBUTE),
            ]);

            return JsonResponse::write($response, [
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'failed',
                ],
            ], 503);
        }
    }
}
