<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Application\Impersonation\ImpersonationService;
use Sova\Identity\Infrastructure\Http\AuthCookieManager;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;

final readonly class LogoutAction
{
    public function __construct(
        private Connection $connection,
        private UserSessionRepository $sessions,
        private AuthenticationEventRecorder $events,
        private AuthCookieManager $cookies,
        private ImpersonationService $impersonations,
    ) {}

    /**
     * @param array<string, string> $args
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
            throw new RuntimeException('Authenticated session context is missing.');
        }

        $requestIdAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
        $requestId = is_string($requestIdAttribute) ? $requestIdAttribute : '';
        $ipAddress = $this->ipAddress($request);
        $this->impersonations->endForSessionClosure(
            $session,
            'LOGOUT',
            $requestId,
            $ipAddress,
        );

        $this->connection->transactional(function () use (
            $session,
            $requestId,
            $ipAddress,
        ): void {
            $this->sessions->revoke(
                $session->sessionId,
                $session->actorUserId,
                'LOGOUT',
            );
            $this->events->record(
                eventType: 'LOGOUT',
                outcome: 'SUCCESS',
                reasonCode: 'LOGOUT_SUCCEEDED',
                requestId: $requestId,
                ipAddress: $ipAddress,
                userId: $session->actorUserId,
                sessionId: $session->sessionId,
            );
        });

        return $this->cookies->clearAuthenticationCookies(
            $response->withStatus(204),
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
