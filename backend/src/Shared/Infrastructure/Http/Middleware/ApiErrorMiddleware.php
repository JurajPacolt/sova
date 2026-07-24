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
use Slim\Exception\HttpException;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Throwable;

final readonly class ApiErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        private Settings $settings,
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
            $status = 500;
            $title = 'Internal Server Error';
            $detail = 'The server could not complete the request.';

            if ($exception instanceof HttpException) {
                $status = $exception->getCode();
                $title = $exception->getTitle();
                $detail = $exception->getDescription();
            } elseif ($this->settings->bool('app.debug', false)) {
                $detail = $exception->getMessage();
            }

            $requestIdAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
            $requestId = is_string($requestIdAttribute) ? $requestIdAttribute : '';
            $context = [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'request_id' => $requestId,
                'status' => $status,
            ];

            if ($status >= 500) {
                $this->logger->error($title, $context);
            } else {
                $this->logger->warning($title, $context);
            }

            $payload = [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->getUri()->getPath(),
                'request_id' => $requestId,
            ];

            $response = $this->responseFactory->createResponse($status);
            $response->getBody()->write(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );

            return $response->withHeader(
                'Content-Type',
                'application/problem+json; charset=utf-8',
            );
        }
    }
}
