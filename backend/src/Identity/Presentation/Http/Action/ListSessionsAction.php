<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\SessionSummary;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class ListSessionsAction
{
    public function __construct(private UserSessionRepository $sessions) {}

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
        $current = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$current instanceof SessionContext) {
            throw new RuntimeException('Authenticated session context is missing.');
        }

        return JsonResponse::write($response, [
            'sessions' => array_map(
                static fn(SessionSummary $session): array => [
                    'id' => $session->id,
                    'ip_address' => $session->ipAddress,
                    'user_agent' => $session->userAgent,
                    'created_at' => $session->createdAt->format(DATE_ATOM),
                    'last_seen_at' => $session->lastSeenAt->format(DATE_ATOM),
                    'expires_at' => $session->expiresAt->format(DATE_ATOM),
                    'current' => $session->id === $current->sessionId,
                ],
                $this->sessions->listActiveForUser($current->actorUserId),
            ),
        ]);
    }
}
