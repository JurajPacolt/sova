<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Invitation\InvitationAccessService;

final readonly class InspectInvitationAction
{
    public function __construct(
        private InvitationAccessService $invitationAccess,
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
        $tokenValue = $payload['token'] ?? null;
        $token = is_string($tokenValue) ? $tokenValue : '';
        $invitation = $this->invitationAccess->inspect($token);

        return JsonResponse::write(
            $response,
            [
                'invitation' => [
                    'tenant_name' => $invitation->tenantName,
                    'tenant_slug' => $invitation->tenantSlug,
                    'email' => $invitation->email,
                    'invited_by_display_name' => $invitation->invitedByDisplayName,
                    'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
                ],
            ],
        );
    }
}
