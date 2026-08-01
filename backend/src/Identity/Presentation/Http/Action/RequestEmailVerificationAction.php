<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Identity\Application\Token\UserActionRequestService;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class RequestEmailVerificationAction
{
    public function __construct(
        private UserActionRequestService $userActionRequests,
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
        $emailValue = $payload['email'] ?? null;
        $email = is_string($emailValue) ? strtolower(trim($emailValue)) : '';

        if (
            $email === ''
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'EMAIL_VERIFICATION_REQUEST_INVALID',
                'The email verification request is invalid.',
                ['email' => ['Enter a valid email address.']],
            );
        }

        $this->userActionRequests->request(
            purpose: OneTimeTokenPurpose::EmailVerification,
            normalizedEmail: $email,
            ipAddress: $this->ipAddress($request),
            requestId: $this->requestId($request),
        );

        return JsonResponse::write(
            $response,
            [
                'message' => 'If the account requires verification, instructions will be sent.',
            ],
            202,
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
