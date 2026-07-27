<?php

declare(strict_types=1);

namespace Sova\Authorization\Presentation\Http\Action;

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
use Sova\Authorization\Application\TenantRoleRepository;
use Sova\Authorization\Domain\Permission;
use Sova\Authorization\Domain\PermissionCatalog;
use Sova\Authorization\Domain\PermissionDefinition;
use Sova\Authorization\Domain\PermissionScope;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class TenantRolesAction
{
    public function __construct(
        private TenantRoleRepository $roles,
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
        $scope = AuthorizationScope::tenant($tenant->id);

        if ($request->getMethod() === 'GET') {
            $this->authorization->require(
                $subject,
                Permission::TenantRolesView,
                $scope,
            );

            return JsonResponse::write(
                $response,
                [
                    'roles' => array_map(
                        $this->serializeRole(...),
                        $this->roles->listForTenant($tenant->id),
                    ),
                    'permissions' => array_values(array_map(
                        $this->serializePermission(...),
                        array_filter(
                            PermissionCatalog::all(),
                            static fn(
                                PermissionDefinition $definition,
                            ): bool => $definition->permission->scope()
                                !== PermissionScope::System,
                        ),
                    )),
                ],
            );
        }

        if ($request->getMethod() !== 'POST') {
            throw new RuntimeException(
                'Unsupported tenant role collection operation.',
            );
        }

        $this->authorization->require(
            $subject,
            Permission::TenantRolesManage,
            $scope,
        );
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $role = $this->roleDefinitions->create(
            tenantId: $tenant->id,
            input: $this->validator->forCreate($payload),
            actorUserId: $session->actorUserId,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
            effectiveUserId: $session->effectiveUserIdForAudit(),
        );

        return JsonResponse::write(
            $response,
            ['role' => $this->serializeRole($role)],
            201,
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
     * @return array<string, bool|string|list<string>>
     */
    private function serializePermission(
        PermissionDefinition $definition,
    ): array {
        return [
            'code' => $definition->permission->value,
            'scope' => $definition->permission->scope()->value,
            'label' => $definition->label,
            'description' => $definition->description,
            'sensitive' => $definition->permission->isSensitive(),
            'dependencies' => array_map(
                static fn(Permission $permission): string => $permission->value,
                $definition->dependencies,
            ),
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
                'Tenant role operation requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
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
