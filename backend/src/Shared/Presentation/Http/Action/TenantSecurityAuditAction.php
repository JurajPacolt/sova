<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http\Action;

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
use Sova\Shared\Application\Audit\AuditQueryValidator;
use Sova\Shared\Application\Audit\SecurityAuditReader;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Shared\Presentation\Http\SecurityAuditSerializer;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class TenantSecurityAuditAction
{
    public function __construct(
        private SecurityAuditReader $reader,
        private AuditQueryValidator $validator,
        private SecurityAuditSerializer $serializer,
        private AuthorizationService $authorization,
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
        $session = $this->session($request);
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$tenant instanceof AccessibleTenant) {
            throw new RuntimeException(
                'Tenant audit access requires a tenant context.',
            );
        }

        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::TenantAuditView,
            AuthorizationScope::tenant($tenant->id),
        );
        $query = $this->validator->validate($request->getQueryParams());
        $page = $this->reader->page($query, $tenant->id);
        $this->audit->record(
            eventType: 'TENANT_AUDIT_VIEWED',
            outcome: 'SUCCESS',
            reasonCode: 'TENANT_AUDIT_VIEWED',
            requestId: $this->requestId($request),
            actorUserId: $session->actorUserId,
            tenantId: $tenant->id,
            effectiveUserId: $session->effectiveUserIdForAudit(),
            ipAddress: $this->ipAddress($request),
            metadata: ['result_count' => count($page->events)],
        );

        return JsonResponse::write(
            $response,
            $this->serializer->page($page),
        );
    }

    private function session(ServerRequestInterface $request): SessionContext
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'Tenant audit access requires a session context.',
            );
        }

        return $session;
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
