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

final readonly class RegenerateMfaRecoveryCodesAction
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
                'MFA recovery-code regeneration requires a session context.',
            );
        }

        [$password, $code] = $this->validatedInput($request);
        try {
            $confirmation = $this->mfa->regenerateRecoveryCodes(
                session: $session,
                currentPassword: $password,
                code: $code,
                requestId: $this->requestId($request),
                ipAddress: $this->ipAddress($request),
            );
        } catch (DomainProblemException $exception) {
            $this->audit->record(
                eventType: 'MFA_RECOVERY_CODES_REGENERATED',
                outcome: 'FAILURE',
                reasonCode: $exception->problemCode(),
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                ipAddress: $this->ipAddress($request),
            );

            throw $exception;
        }

        return JsonResponse::write($response, [
            'recovery_codes' => $confirmation->recoveryCodes,
            'recovery_codes_remaining' => count(
                $confirmation->recoveryCodes,
            ),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{string, string}
     */
    private function validatedInput(
        ServerRequestInterface $request,
    ): array {
        $payload = $request->getParsedBody();
        $body = is_array($payload) ? $payload : [];
        $passwordValue = $body['current_password'] ?? null;
        $codeValue = $body['code'] ?? null;
        $password = is_string($passwordValue) ? $passwordValue : '';
        $code = is_string($codeValue) ? trim($codeValue) : '';
        $errors = [];

        if ($password === '' || strlen($password) > 1024) {
            $errors['current_password'] = ['Enter your current password.'];
        }

        if ($code === '' || strlen($code) > 64) {
            $errors['code'] = ['Enter an authentication or recovery code.'];
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'MFA_RECOVERY_CODES_INPUT_INVALID',
                'The recovery-code input is invalid.',
                $errors,
            );
        }

        return [$password, $code];
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
