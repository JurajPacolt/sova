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

final readonly class ConfirmMfaEnrollmentAction
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
                'MFA enrollment confirmation requires a session context.',
            );
        }

        $payload = $request->getParsedBody();
        $body = is_array($payload) ? $payload : [];
        $codeValue = $body['code'] ?? null;
        $code = is_string($codeValue) ? trim($codeValue) : '';

        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'MFA_CONFIRMATION_INPUT_INVALID',
                'The MFA confirmation input is invalid.',
                ['code' => ['Enter the six-digit authenticator code.']],
            );
        }

        try {
            $confirmation = $this->mfa->confirmEnrollment(
                session: $session,
                code: $code,
                requestId: $this->requestId($request),
                ipAddress: $this->ipAddress($request),
            );
        } catch (DomainProblemException $exception) {
            $this->audit->record(
                eventType: 'MFA_ENABLED',
                outcome: 'FAILURE',
                reasonCode: $exception->problemCode(),
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                ipAddress: $this->ipAddress($request),
            );

            throw $exception;
        }

        return JsonResponse::write($response, [
            'mfa' => [
                'enabled' => true,
                'verified' => true,
                'enrollment_required' => false,
                'recovery_codes_remaining' => count(
                    $confirmation->recoveryCodes,
                ),
            ],
            'recovery_codes' => $confirmation->recoveryCodes,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
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
