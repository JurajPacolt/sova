<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Mfa\MfaService;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class BeginMfaEnrollmentAction
{
    public function __construct(
        private MfaService $mfa,
        private SecurityAuditRecorder $audit,
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
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'MFA enrollment requires a session context.',
            );
        }

        $payload = $request->getParsedBody();
        $body = is_array($payload) ? $payload : [];
        $passwordValue = $body['current_password'] ?? null;
        $password = is_string($passwordValue) ? $passwordValue : '';

        if ($password === '' || strlen($password) > 1024) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'MFA_ENROLLMENT_INPUT_INVALID',
                'The MFA enrollment input is invalid.',
                ['current_password' => ['Enter your current password.']],
            );
        }

        try {
            $enrollment = $this->mfa->beginEnrollment(
                session: $session,
                currentPassword: $password,
                requestId: $this->requestId($request),
                ipAddress: $this->ipAddress($request),
            );
        } catch (DomainProblemException $exception) {
            $this->recordFailure(
                $request,
                $session,
                'MFA_ENROLLMENT_STARTED',
                $exception,
            );

            throw $exception;
        }

        return JsonResponse::write($response, [
            'secret' => $enrollment->secret,
            'otpauth_uri' => $enrollment->otpauthUri,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
    }

    private function recordFailure(
        ServerRequestInterface $request,
        SessionContext $session,
        string $eventType,
        DomainProblemException $exception,
    ): void {
        $this->audit->record(
            eventType: $eventType,
            outcome: 'FAILURE',
            reasonCode: $exception->problemCode(),
            requestId: $this->requestId($request),
            actorUserId: $session->actorUserId,
            ipAddress: $this->ipAddress($request),
        );
    }

    private function ipAddress(ServerRequestInterface $request): ?string
    {
        $value = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_IP) !== false
                ? $value
                : null;
    }
}
