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
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class GetTenantAction
{
    public function __construct(private AuthorizationService $authorization) {}

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
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (
            !$tenant instanceof AccessibleTenant
            || !$session instanceof SessionContext
        ) {
            throw new RuntimeException('Tenant context is missing.');
        }

        return JsonResponse::write($response, [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'access' => [
                    'type' => $tenant->viaSuperadmin ? 'SUPERADMIN' : 'MEMBERSHIP',
                    'membership_id' => $tenant->membershipId,
                ],
            ],
            // UX affordances only. Every operation authorizes itself again on
            // its own endpoint, so a stale list can never widen access.
            'permissions' => $this->permissionCodes($session, $tenant->id),
        ]);
    }

    /**
     * @return list<string>
     */
    private function permissionCodes(
        SessionContext $session,
        string $tenantId,
    ): array {
        $granted = $this->authorization->grantedPermissions(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            AuthorizationScope::tenant($tenantId),
        );
        $codes = array_map(
            static fn(Permission $permission): string => $permission->value,
            $granted,
        );
        sort($codes);

        return $codes;
    }
}
