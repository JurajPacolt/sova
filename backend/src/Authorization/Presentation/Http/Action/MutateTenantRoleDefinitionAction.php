<?php

declare(strict_types=1);

namespace Sova\Authorization\Presentation\Http\Action;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Application\TenantRoleDefinitionService;
use Sova\Authorization\Application\TenantRoleDefinitionValidator;
use Sova\Authorization\Application\TenantRoleDetails;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class MutateTenantRoleDefinitionAction
{
    public function __construct(
        private TenantRoleDefinitionService $roleDefinitions,
        private TenantRoleDefinitionValidator $validator,
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
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );
        $this->authorization->require(
            $subject,
            Permission::TenantRolesManage,
            AuthorizationScope::tenant($tenant->id),
        );
        $roleId = $this->roleId($args['roleId'] ?? '');

        if ($request->getMethod() === 'PUT') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $role = $this->roleDefinitions->update(
                tenantId: $tenant->id,
                roleId: $roleId,
                input: $this->validator->forUpdate($payload),
                actorUserId: $session->actorUserId,
                requestId: $this->requestId($request),
                ipAddress: $this->ipAddress($request),
                effectiveUserId: $session->effectiveUserIdForAudit(),
            );

            return JsonResponse::write(
                $response,
                ['role' => $this->serializeRole($role)],
            );
        }

        if ($request->getMethod() === 'DELETE') {
            $this->roleDefinitions->archive(
                tenantId: $tenant->id,
                roleId: $roleId,
                actorUserId: $session->actorUserId,
                requestId: $this->requestId($request),
                ipAddress: $this->ipAddress($request),
                effectiveUserId: $session->effectiveUserIdForAudit(),
            );

            return $response->withStatus(204);
        }

        throw new RuntimeException(
            'Unsupported tenant role definition operation.',
        );
    }

    /**
     * @return array<string, bool|int|string|list<string>>
     */
    private function serializeRole(TenantRoleDetails $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'status' => $role->status,
            'is_system' => $role->isSystem,
            'is_editable' => $role->isEditable,
            'revision' => $role->revision,
            'permissions' => $role->permissionCodes,
            'assignment_count' => $role->assignmentCount,
        ];
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
                'Tenant role mutation requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }

    private function roleId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'TENANT_ROLE_NOT_FOUND',
                'The tenant role was not found.',
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
