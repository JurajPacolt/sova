<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Presentation\Http\ImpersonationSerializer;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class GetCurrentSessionAction
{
    public function __construct(
        private ImpersonationSerializer $impersonations,
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
                'Current session endpoint requires a session context.',
            );
        }

        return JsonResponse::write($response, [
            'user' => [
                'id' => $session->userId,
                'email' => $session->email,
                'display_name' => $session->displayName,
                'preferred_locale' => $session->preferredLocale,
                'is_superadmin' => $session->isSuperadmin,
            ],
            'impersonation' => $session->impersonation === null
                ? null
                : $this->impersonations->serialize(
                    $session->impersonation,
                ),
        ]);
    }
}
