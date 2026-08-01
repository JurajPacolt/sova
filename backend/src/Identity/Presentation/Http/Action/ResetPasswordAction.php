<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Identity\Application\PasswordRecovery\ResetPasswordService;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;

final readonly class ResetPasswordAction
{
    public function __construct(
        private ResetPasswordService $passwordReset,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $tokenValue = $payload['token'] ?? null;
        $passwordValue = $payload['password'] ?? null;
        $confirmationValue = $payload['password_confirmation'] ?? null;
        $token = is_string($tokenValue) ? $tokenValue : '';
        $password = is_string($passwordValue) ? $passwordValue : '';
        $confirmation = is_string($confirmationValue) ? $confirmationValue : '';
        $errors = [];

        if ($password === '' || strlen($password) > 1024) {
            $errors['password'] = ['Enter a password of at most 1024 bytes.'];
        }

        if ($confirmation === '' || !hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = [
                'The password confirmation must match.',
            ];
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PASSWORD_RESET_INPUT_INVALID',
                'The password reset input is invalid.',
                $errors,
            );
        }

        $this->passwordReset->reset(
            plainTextToken: $token,
            newPassword: $password,
            ipAddress: $this->ipAddress($request),
            requestId: $this->requestId($request),
        );

        return $response->withStatus(204);
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
