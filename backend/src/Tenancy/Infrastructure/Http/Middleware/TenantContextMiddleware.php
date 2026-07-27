<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Http\Middleware;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Tenancy\Application\Access\TenantAccessRepository;
use Throwable;

final readonly class TenantContextMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'tenant_context';

    public function __construct(
        private TenantAccessRepository $tenants,
        private SecurityAuditRecorder $audit,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException('Authenticated session context is missing.');
        }

        $route = RouteContext::fromRequest($request)->getRoute();
        $tenantIdValue = $route?->getArgument('tenantId');
        $impersonation = $session->impersonation;

        try {
            $tenantId = (string) UuidV7::fromString(
                is_string($tenantIdValue) ? $tenantIdValue : '',
            );
        } catch (InvalidArgumentException) {
            if ($session->isSuperadmin) {
                $this->audit->record(
                    eventType: 'SUPERADMIN_TENANT_CONTEXT_ENTERED',
                    outcome: 'FAILURE',
                    reasonCode: 'TENANT_IDENTIFIER_INVALID',
                    requestId: $this->requestId($request),
                    actorUserId: $session->actorUserId,
                    ipAddress: $this->ipAddress($request),
                    metadata: ['identifier_valid' => false],
                );
            }

            if ($impersonation !== null) {
                $this->audit->record(
                    eventType: 'IMPERSONATION_REQUEST',
                    outcome: 'FAILURE',
                    reasonCode: 'TENANT_IDENTIFIER_INVALID',
                    requestId: $this->requestId($request),
                    actorUserId: $session->actorUserId,
                    tenantId: $impersonation->tenantId,
                    effectiveUserId: $session->userId,
                    ipAddress: $this->ipAddress($request),
                    metadata: [
                        'impersonation_id' => $impersonation->id,
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                    ],
                );
            }

            throw $this->notFound();
        }

        if (
            $impersonation !== null
            && $impersonation->tenantId !== $tenantId
        ) {
            $this->audit->record(
                eventType: 'IMPERSONATION_REQUEST',
                outcome: 'FAILURE',
                reasonCode: 'TENANT_SCOPE_MISMATCH',
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                tenantId: $impersonation->tenantId,
                effectiveUserId: $session->userId,
                ipAddress: $this->ipAddress($request),
                metadata: [
                    'impersonation_id' => $impersonation->id,
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'requested_tenant_id' => $tenantId,
                ],
            );

            throw $this->notFound();
        }

        $tenant = $this->tenants->findAccessibleById(
            $tenantId,
            $session->userId,
            $session->isSuperadmin,
        );

        if ($tenant === null) {
            if ($session->isSuperadmin) {
                $this->audit->record(
                    eventType: 'SUPERADMIN_TENANT_CONTEXT_ENTERED',
                    outcome: 'FAILURE',
                    reasonCode: 'TENANT_NOT_ACCESSIBLE',
                    requestId: $this->requestId($request),
                    actorUserId: $session->actorUserId,
                    ipAddress: $this->ipAddress($request),
                    metadata: ['requested_tenant_id' => $tenantId],
                );
            }

            if ($impersonation !== null) {
                $this->audit->record(
                    eventType: 'IMPERSONATION_REQUEST',
                    outcome: 'FAILURE',
                    reasonCode: 'TENANT_NOT_ACCESSIBLE',
                    requestId: $this->requestId($request),
                    actorUserId: $session->actorUserId,
                    tenantId: $impersonation->tenantId,
                    effectiveUserId: $session->userId,
                    ipAddress: $this->ipAddress($request),
                    metadata: [
                        'impersonation_id' => $impersonation->id,
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                    ],
                );
            }

            throw $this->notFound();
        }

        if ($tenant->viaSuperadmin) {
            $this->audit->record(
                eventType: 'SUPERADMIN_TENANT_CONTEXT_ENTERED',
                outcome: 'SUCCESS',
                reasonCode: 'SUPERADMIN_ACCESS',
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                tenantId: $tenant->id,
                ipAddress: $this->ipAddress($request),
            );
        }

        $request = $request->withAttribute(self::ATTRIBUTE, $tenant);

        if ($impersonation === null) {
            return $handler->handle($request);
        }

        try {
            $response = $handler->handle($request);
            $this->recordImpersonationRequest(
                $request,
                $session,
                $tenant->id,
                $response->getStatusCode() < 400 ? 'SUCCESS' : 'FAILURE',
                $response->getStatusCode() < 400
                    ? 'REQUEST_COMPLETED'
                    : 'REQUEST_REJECTED',
                $response->getStatusCode(),
            );

            return $response;
        } catch (Throwable $exception) {
            $this->recordImpersonationRequest(
                $request,
                $session,
                $tenant->id,
                'FAILURE',
                'REQUEST_FAILED',
            );

            throw $exception;
        }
    }

    private function recordImpersonationRequest(
        ServerRequestInterface $request,
        SessionContext $session,
        string $tenantId,
        string $outcome,
        string $reasonCode,
        ?int $statusCode = null,
    ): void {
        $impersonation = $session->impersonation;

        if ($impersonation === null) {
            return;
        }

        $this->audit->record(
            eventType: 'IMPERSONATION_REQUEST',
            outcome: $outcome,
            reasonCode: $reasonCode,
            requestId: $this->requestId($request),
            actorUserId: $session->actorUserId,
            tenantId: $tenantId,
            effectiveUserId: $session->userId,
            ipAddress: $this->ipAddress($request),
            metadata: [
                'impersonation_id' => $impersonation->id,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status_code' => $statusCode,
            ],
        );
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_NOT_FOUND',
            'The tenant was not found.',
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
