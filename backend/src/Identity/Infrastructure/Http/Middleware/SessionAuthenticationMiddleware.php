<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sova\Identity\Application\Authentication\SessionAuthenticator;
use Sova\Identity\Infrastructure\Http\AuthCookieManager;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class SessionAuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'authenticated_session';

    public function __construct(
        private SessionAuthenticator $authenticator,
        private AuthCookieManager $cookies,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $cookieValue = $request->getCookieParams()[$this->cookies->sessionCookieName()]
            ?? null;
        $plainTextToken = is_string($cookieValue) ? $cookieValue : null;
        $session = $this->authenticator->authenticate($plainTextToken);
        $impersonation = $session->impersonation;

        if (
            $impersonation !== null
            && !$impersonation->status->isUsable()
            && !$this->allowsUnusableImpersonation($request)
        ) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                $impersonation->status->value === 'EXPIRED'
                    ? 'IMPERSONATION_EXPIRED'
                    : 'IMPERSONATION_INVALIDATED',
                $impersonation->status->value === 'EXPIRED'
                    ? 'The impersonation has expired and must be ended.'
                    : 'The impersonation is no longer valid and must be ended.',
            );
        }

        if (
            $session->mfaEnrollmentRequired
            && !$this->allowsMfaEnrollment($request)
        ) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'MFA_ENROLLMENT_REQUIRED',
                'Multi-factor authentication enrollment is required before this session can access SOVA.',
            );
        }

        return $handler->handle(
            $request->withAttribute(self::ATTRIBUTE, $session),
        );
    }

    private function allowsMfaEnrollment(
        ServerRequestInterface $request,
    ): bool {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        return ($method === 'GET' && (
            $path === '/api/v1/auth/session'
            || $path === '/api/v1/auth/mfa'
        )) || ($method === 'POST' && (
            $path === '/api/v1/auth/logout'
            || $path === '/api/v1/auth/mfa/enrollment'
            || $path === '/api/v1/auth/mfa/enrollment/confirm'
        ));
    }

    private function allowsUnusableImpersonation(
        ServerRequestInterface $request,
    ): bool {
        $path = $request->getUri()->getPath();

        return $path === '/api/v1/auth/session'
            || $path === '/api/v1/auth/logout'
            || (
                $path === '/api/v1/system/impersonations/current'
                && $request->getMethod() === 'DELETE'
            );
    }
}
