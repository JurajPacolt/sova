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
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Application\Membership\TenantMembershipRepository;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;
use Sova\Tenancy\Presentation\Http\TenantMembershipSerializer;

final readonly class TenantMembershipsAction
{
    public function __construct(
        private TenantMembershipRepository $memberships,
        private TenantMembershipSerializer $serializer,
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
        [$session, $tenant] = $this->contexts($request);
        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::TenantMembersView,
            AuthorizationScope::tenant($tenant->id),
        );

        return JsonResponse::write($response, [
            'memberships' => array_map(
                $this->serializer->serialize(...),
                $this->memberships->listForTenant($tenant->id),
            ),
        ]);
    }

    /**
     * @return array{SessionContext, AccessibleTenant}
     */
    private function contexts(ServerRequestInterface $request): array
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (
            !$session instanceof SessionContext
            || !$tenant instanceof AccessibleTenant
        ) {
            throw new RuntimeException(
                'Tenant membership list requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }
}
