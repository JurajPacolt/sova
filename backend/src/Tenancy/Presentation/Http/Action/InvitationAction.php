<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use DateTimeImmutable;
use Exception;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Invitation\InvitationAdministrationService;
use Sova\Tenancy\Presentation\Http\InvitationContext;
use Sova\Tenancy\Presentation\Http\InvitationSerializer;

final readonly class InvitationAction
{
    public function __construct(
        private InvitationAdministrationService $invitations,
        private InvitationSerializer $serializer,
        private InvitationContext $context,
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
        [$session, $tenant, $subject] = $this->context->resolve($request);
        $this->authorization->require(
            $subject,
            Permission::TenantMembersInvite,
            AuthorizationScope::tenant($tenant->id),
        );
        $invitationId = $args['invitationId'] ?? '';

        if ($request->getMethod() === 'DELETE') {
            $invitation = $this->invitations->revoke(
                tenantId: $tenant->id,
                invitationId: $invitationId,
                actorUserId: $session->actorUserId,
                requestId: $this->context->requestId($request),
                ipAddress: $this->context->ipAddress($request),
                effectiveUserId: $session->effectiveUserIdForAudit(),
            );
        } else {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $invitation = $this->invitations->changeExpiry(
                tenantId: $tenant->id,
                invitationId: $invitationId,
                expiresAt: $this->expiry($payload['expires_at'] ?? null),
                actorUserId: $session->actorUserId,
                requestId: $this->context->requestId($request),
                ipAddress: $this->context->ipAddress($request),
                effectiveUserId: $session->effectiveUserIdForAudit(),
            );
        }

        return JsonResponse::write($response, [
            'invitation' => $this->serializer->serialize($invitation),
        ]);
    }

    private function expiry(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1
        ) {
            throw $this->invalidExpiry();
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw $this->invalidExpiry();
        }
    }

    private function invalidExpiry(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'INVITATION_EXPIRY_INVALID',
            'The invitation expiry is invalid.',
            ['expires_at' => ['Enter an ISO 8601 date and time with a timezone.']],
        );
    }
}
