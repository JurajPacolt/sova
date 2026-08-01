<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http\Middleware;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Sova\Shared\Infrastructure\Http\ProblemDetailsFactory;
use Throwable;

final readonly class ApiErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        private ProblemDetailsFactory $problemDetailsFactory,
    ) {}

    /**
     * @throws JsonException
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $requestIdAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
            $requestId = is_string($requestIdAttribute) ? $requestIdAttribute : '';
            $problem = $this->problemDetailsFactory->fromThrowable(
                $exception,
                $request->getUri()->getPath(),
                $requestId,
            );
            $context = [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'problem_code' => $problem->code,
                'request_id' => $requestId,
                'status' => $problem->status,
            ];

            if ($problem->status >= 500) {
                $this->logger->error($problem->title, $context);
            } else {
                $this->logger->warning($problem->title, $context);
            }

            $response = $this->responseFactory->createResponse($problem->status);
            $response->getBody()->write(
                json_encode(
                    $problem->toArray(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            );

            return $response->withHeader(
                'Content-Type',
                'application/problem+json; charset=utf-8',
            );
        }
    }
}
