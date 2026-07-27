<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Application\Impersonation\ImpersonationService;
use Sova\Identity\Infrastructure\Http\AuthCookieManager;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;

final readonly class RevokeSessionAction
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
        $current = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$current instanceof SessionContext) {
            throw new RuntimeException('Authenticated session context is missing.');
        }

        $sessionId = $args['sessionId'] ?? '';

        try {
            $sessionId = (string) UuidV7::fromString($sessionId);
        } catch (InvalidArgumentException) {
            throw $this->notFound();
        }

        $requestIdAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
        $requestId = is_string($requestIdAttribute) ? $requestIdAttribute : '';
        $ipAddress = $this->ipAddress($request);
        $revoked = $this->connection->transactional(function () use (
            $sessionId,
            $current,
            $requestId,
            $ipAddress,
        ): bool {
            if ($sessionId === $current->sessionId) {
                $this->impersonations->endForSessionClosure(
                    $current,
                    'SESSION_REVOKED',
                    $requestId,
                    $ipAddress,
                );
            }

            $revoked = $this->sessions->revoke(
                $sessionId,
                $current->actorUserId,
                'USER_REVOKED',
            );

            if ($revoked) {
                $this->events->record(
                    eventType: 'SESSION_REVOKED',
                    outcome: 'SUCCESS',
                    reasonCode: 'SESSION_REVOKED',
                    requestId: $requestId,
                    ipAddress: $ipAddress,
                    userId: $current->actorUserId,
                    sessionId: $sessionId,
                );
            }

            return $revoked;
        });

        if (!$revoked) {
            throw $this->notFound();
        }

        $response = $response->withStatus(204);

        return $sessionId === $current->sessionId
            ? $this->cookies->clearAuthenticationCookies($response)
            : $response;
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'SESSION_NOT_FOUND',
            'The session was not found.',
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
