<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Application\Invitation\CreateInvitationService;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class CreateInvitationAction
{
    public function __construct(
        private CreateInvitationService $invitationCreation,
        private AuthorizationService $authorization,
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
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (
            !$session instanceof SessionContext
            || !$tenant instanceof AccessibleTenant
        ) {
            throw new RuntimeException(
                'Invitation creation requires session and tenant contexts.',
            );
        }

        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::TenantMembersInvite,
            AuthorizationScope::tenant($tenant->id),
        );

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $emailValue = $payload['email'] ?? null;
        $email = is_string($emailValue) ? strtolower(trim($emailValue)) : '';

        if (
            $email === ''
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'INVITATION_INPUT_INVALID',
                'The invitation input is invalid.',
                ['email' => ['Enter a valid email address.']],
            );
        }

        $invitation = $this->invitationCreation->create(
            tenantId: $tenant->id,
            normalizedEmail: $email,
            actorUserId: $session->actorUserId,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
            effectiveUserId: $session->effectiveUserIdForAudit(),
        );

        return JsonResponse::write(
            $response,
            [
                'invitation' => [
                    'id' => $invitation->id,
                    'tenant_id' => $invitation->tenantId,
                    'email' => $invitation->email,
                    'status' => 'PENDING',
                    'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
                ],
            ],
            201,
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
