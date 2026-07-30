<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http;

use InvalidArgumentException;
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
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Shared resolution and authorization for the project workflow configuration
 * routes. Authoring needs `project.workflow.manage` and publishing the stricter
 * `project.workflow.publish`; a tenant-wide project manager passes either.
 */
final readonly class WorkflowConfigurationRequestContext
{
    public function __construct(private AuthorizationService $authorization) {}

    /**
     * @param array<string, string> $args
     */
    public function resolve(
        ServerRequestInterface $request,
        array $args,
    ): ResolvedWorkflowRequest {
        [$session, $tenant] = $this->contexts($request);

        return new ResolvedWorkflowRequest(
            $session,
            $tenant,
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            $this->identifier($args['projectId'] ?? ''),
        );
    }

    public function requireManage(ResolvedWorkflowRequest $resolved): void
    {
        $this->require($resolved, Permission::ProjectWorkflowManage);
    }

    public function requirePublish(ResolvedWorkflowRequest $resolved): void
    {
        $this->require($resolved, Permission::ProjectWorkflowPublish);
    }

    /**
     * @param array<string, string> $args
     */
    public function workflowId(array $args): string
    {
        return $this->identifier($args['workflowId'] ?? '');
    }

    /**
     * @param array<string, string> $args
     */
    public function issueTypeId(array $args): string
    {
        return $this->identifier($args['issueTypeId'] ?? '');
    }

    public function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
    }

    public function ipAddress(ServerRequestInterface $request): ?string
    {
        $value = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_IP) !== false
                ? $value
                : null;
    }

    private function require(ResolvedWorkflowRequest $resolved, Permission $permission): void
    {
        // A tenant-wide project manager may configure any project; otherwise the
        // caller needs the project-scoped permission.
        if ($this->authorization->isGranted(
            $resolved->subject,
            Permission::TenantProjectsManage,
            AuthorizationScope::tenant($resolved->tenant->id),
        )) {
            return;
        }

        $this->authorization->require(
            $resolved->subject,
            $permission,
            AuthorizationScope::project($resolved->tenant->id, $resolved->projectId),
        );
    }

    /**
     * @return array{SessionContext, AccessibleTenant}
     */
    private function contexts(ServerRequestInterface $request): array
    {
        $session = $request->getAttribute(SessionAuthenticationMiddleware::ATTRIBUTE);
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (
            !$session instanceof SessionContext
            || !$tenant instanceof AccessibleTenant
        ) {
            throw new RuntimeException(
                'Workflow configuration requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }

    private function identifier(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            // A malformed or foreign identifier reads as missing rather than
            // confirming what exists in another project or tenant.
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'PROJECT_RESOURCE_NOT_FOUND',
                'The requested resource was not found in this project.',
            );
        }
    }
}
