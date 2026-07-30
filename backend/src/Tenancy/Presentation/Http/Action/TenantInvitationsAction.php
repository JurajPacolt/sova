<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Invitation\CreateInvitationService;
use Sova\Tenancy\Application\Invitation\InvitationAdministrationService;
use Sova\Tenancy\Presentation\Http\InvitationContext;
use Sova\Tenancy\Presentation\Http\InvitationSerializer;

final readonly class TenantInvitationsAction
{
    public function __construct(
        private CreateInvitationService $invitationCreation,
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

        if ($request->getMethod() === 'GET') {
            return JsonResponse::write($response, [
                'invitations' => array_map(
                    $this->serializer->serialize(...),
                    $this->invitations->list($tenant->id),
                ),
            ]);
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $emailValue = $payload['email'] ?? null;
        $email = is_string($emailValue)
            ? strtolower(trim($emailValue))
            : '';

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
            requestId: $this->context->requestId($request),
            ipAddress: $this->context->ipAddress($request),
            effectiveUserId: $session->effectiveUserIdForAudit(),
        );

        return JsonResponse::write(
            $response,
            ['invitation' => $this->serializer->serializeCreated($invitation)],
            201,
        );
    }
}
