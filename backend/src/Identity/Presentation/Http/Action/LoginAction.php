<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Identity\Application\Authentication\LoginService;
use Sova\Identity\Infrastructure\Http\AuthCookieManager;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class LoginAction
{
    public function __construct(
        private LoginService $loginService,
        private AuthCookieManager $cookies,
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
        $passwordValue = $payload['password'] ?? null;
        $email = is_string($emailValue) ? trim($emailValue) : '';
        $password = is_string($passwordValue) ? $passwordValue : '';
        $errors = [];

        if (
            $email === ''
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = ['Enter a valid email address.'];
        }

        if ($password === '' || strlen($password) > 1024) {
            $errors['password'] = ['Enter your password.'];
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'LOGIN_INPUT_INVALID',
                'The login input is invalid.',
                $errors,
            );
        }

        $requestIdAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
        $requestId = is_string($requestIdAttribute) ? $requestIdAttribute : '';
        $serverParams = $request->getServerParams();
        $remoteAddress = $serverParams['REMOTE_ADDR'] ?? null;
        $ipAddress = is_string($remoteAddress) ? $remoteAddress : 'unknown';
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        $result = $this->loginService->login(
            normalizedEmail: strtolower($email),
            plainTextPassword: $password,
            ipAddress: $ipAddress,
            userAgent: $userAgent === '' ? null : $userAgent,
            requestId: $requestId,
        );
        $response = JsonResponse::write($response, [
            'user' => [
                'id' => $result->user->id,
                'email' => $result->user->email,
                'display_name' => $result->user->displayName,
                'preferred_locale' => $result->user->preferredLocale,
                'is_superadmin' => $result->user->isSuperadmin,
            ],
            'session' => [
                'id' => $result->sessionId,
                'expires_at' => $result->expiresAt->format(DATE_ATOM),
            ],
        ]);

        return $this->cookies->withAuthenticationCookies(
            $response,
            $result->sessionToken->plainText(),
            $result->csrfToken->plainText(),
            $result->expiresAt,
        );
    }
}
