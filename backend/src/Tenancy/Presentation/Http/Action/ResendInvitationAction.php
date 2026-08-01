<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Invitation\InvitationAdministrationService;
use Sova\Tenancy\Presentation\Http\InvitationContext;
use Sova\Tenancy\Presentation\Http\InvitationSerializer;

final readonly class ResendInvitationAction
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
        $invitation = $this->invitations->resend(
            tenantId: $tenant->id,
            invitationId: $args['invitationId'] ?? '',
            actorUserId: $session->actorUserId,
            requestId: $this->context->requestId($request),
            ipAddress: $this->context->ipAddress($request),
            effectiveUserId: $session->effectiveUserIdForAudit(),
        );

        return JsonResponse::write($response, [
            'invitation' => $this->serializer->serialize($invitation),
        ]);
    }
}
