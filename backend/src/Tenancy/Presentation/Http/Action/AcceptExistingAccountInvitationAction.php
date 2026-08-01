<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Invitation\InvitationAccessService;

final readonly class AcceptExistingAccountInvitationAction
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
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'Existing-account invitation acceptance requires a session.',
            );
        }

        if ($session->impersonation !== null) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'IMPERSONATION_OPERATION_FORBIDDEN',
                'Invitations cannot be accepted while impersonating another user.',
            );
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $tokenValue = $payload['token'] ?? null;
        $token = is_string($tokenValue) ? $tokenValue : '';
        $accepted = $this->invitationAccess->acceptExistingAccount(
            plainTextToken: $token,
            session: $session,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
        );

        return JsonResponse::write(
            $response,
            [
                'user_id' => $accepted->userId,
                'tenant_id' => $accepted->tenantId,
                'tenant_slug' => $accepted->tenantSlug,
                'membership_created' => $accepted->membershipCreated,
            ],
        );
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
