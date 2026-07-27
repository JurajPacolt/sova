<?php

declare(strict_types=1);

namespace Sova\Projects\Presentation\Http\Action;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Projects\Application\ProjectRoleAssignmentService;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class MutateProjectRoleAssignmentAction
{
    public function __construct(
        private ProjectRoleAssignmentService $assignments,
        private AuthorizationService $authorization,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$session, $tenant] = $this->contexts($request);
        $projectId = $this->identifier(
            $args['projectId'] ?? '',
            'PROJECT_NOT_FOUND',
            'The project was not found.',
        );
        $membershipId = $this->identifier(
            $args['membershipId'] ?? '',
            'TENANT_MEMBERSHIP_NOT_FOUND',
            'The tenant membership was not found.',
        );
        $roleId = $this->identifier(
            $args['roleId'] ?? '',
            'PROJECT_ROLE_NOT_FOUND',
            'The project role was not found.',
        );
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );
        $this->requireProjectMembersManage($subject, $tenant->id, $projectId);
        $arguments = [
            $tenant->id,
            $projectId,
            $membershipId,
            $roleId,
            $session->actorUserId,
            $this->requestId($request),
            $this->ipAddress($request),
        ];

        if ($request->getMethod() === 'PUT') {
            $this->assignments->assign(...$arguments);
        } elseif ($request->getMethod() === 'DELETE') {
            $this->assignments->unassign(...$arguments);
        } else {
            throw new RuntimeException(
                'Unsupported project role assignment operation.',
            );
        }

        return $response->withStatus(204);
    }

    private function requireProjectMembersManage(
        AuthorizationSubject $subject,
        string $tenantId,
        string $projectId,
    ): void {
        if ($this->authorization->isGranted(
            $subject,
            Permission::TenantProjectsManage,
            AuthorizationScope::tenant($tenantId),
        )) {
            return;
        }

        $this->authorization->require(
            $subject,
            Permission::ProjectMembersManage,
            AuthorizationScope::project($tenantId, $projectId),
        );
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
                'Project administration requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }

    private function identifier(
        string $value,
        string $problemCode,
        string $detail,
    ): string {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                $problemCode,
                $detail,
            );
        }
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
