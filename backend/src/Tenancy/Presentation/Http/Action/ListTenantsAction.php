<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Application\Access\TenantAccessRepository;

final readonly class ListTenantsAction
{
    public function __construct(
        private TenantAccessRepository $tenants,
        private SecurityAuditRecorder $audit,
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
            throw new RuntimeException('Authenticated session context is missing.');
        }

        $tenants = $this->tenants->listAccessibleTo(
            $session->userId,
            $session->isSuperadmin,
        );
        $impersonation = $session->impersonation;

        if ($session->isSuperadmin) {
            $this->audit->record(
                eventType: 'SUPERADMIN_TENANTS_LIST_VIEWED',
                outcome: 'SUCCESS',
                reasonCode: 'SUPERADMIN_ACCESS',
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                ipAddress: $this->ipAddress($request),
                metadata: ['result_count' => count($tenants)],
            );
        }

        if ($impersonation !== null) {
            $tenants = array_values(array_filter(
                $tenants,
                static fn(AccessibleTenant $tenant): bool => $tenant->id
                    === $impersonation->tenantId,
            ));
            $this->audit->record(
                eventType: 'IMPERSONATION_REQUEST',
                outcome: 'SUCCESS',
                reasonCode: 'TENANT_LIST_VIEWED',
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                tenantId: $impersonation->tenantId,
                effectiveUserId: $session->userId,
                ipAddress: $this->ipAddress($request),
                metadata: [
                    'impersonation_id' => $impersonation->id,
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'result_count' => count($tenants),
                ],
            );
        }

        return JsonResponse::write($response, [
            'tenants' => array_map(
                $this->payload(...),
                $tenants,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AccessibleTenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'access' => [
                'type' => $tenant->viaSuperadmin ? 'SUPERADMIN' : 'MEMBERSHIP',
                'membership_id' => $tenant->membershipId,
            ],
        ];
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
