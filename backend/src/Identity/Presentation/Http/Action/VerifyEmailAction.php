<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Identity\Application\EmailVerification\VerifyEmailService;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class VerifyEmailAction
{
    public function __construct(
        private VerifyEmailService $emailVerification,
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
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $tokenValue = $payload['token'] ?? null;
        $token = is_string($tokenValue) ? $tokenValue : '';
        $outcome = $this->emailVerification->verify(
            plainTextToken: $token,
            ipAddress: $this->ipAddress($request),
            requestId: $this->requestId($request),
        );

        return JsonResponse::write(
            $response,
            ['status' => $outcome->value],
        );
    }

    private function ipAddress(ServerRequestInterface $request): string
    {
        $value = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($value) ? $value : 'unknown';
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
    }
}
