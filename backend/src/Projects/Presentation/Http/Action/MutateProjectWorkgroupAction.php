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
use Sova\Projects\Application\ProjectWorkgroupService;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class MutateProjectWorkgroupAction
{
    public function __construct(
        private ProjectWorkgroupService $workgroups,
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
        $workgroupId = $this->identifier(
            $args['workgroupId'] ?? '',
            'WORKGROUP_NOT_FOUND',
            'The workgroup was not found.',
        );
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );
        $this->requireProjectMembersManage($subject, $tenant->id, $projectId);
        $requestId = $this->requestId($request);
        $ipAddress = $this->ipAddress($request);

        if ($request->getMethod() === 'PUT') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $this->workgroups->link(
                $tenant->id,
                $projectId,
                $workgroupId,
                $this->roleId($payload['role_id'] ?? null),
                $session->actorUserId,
                $requestId,
                $ipAddress,
            );

            return $response->withStatus(204);
        }

        if ($request->getMethod() === 'DELETE') {
            $this->workgroups->unlink(
                $tenant->id,
                $projectId,
                $workgroupId,
                $session->actorUserId,
                $requestId,
                $ipAddress,
            );

            return $response->withStatus(204);
        }

        throw new RuntimeException('Unsupported project workgroup operation.');
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

    private function roleId(mixed $value): string
    {
        try {
            return (string) UuidV7::fromString(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PROJECT_WORKGROUP_ROLE_ID_INVALID',
                'Provide a valid project role ID.',
                ['role_id' => ['Provide a valid project role ID.']],
            );
        }
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
