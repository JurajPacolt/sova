<?php

declare(strict_types=1);

namespace Sova\Authorization\Presentation\Http\Action;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Application\TenantRoleAssignmentService;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class MutateTenantRoleAssignmentAction
{
    public function __construct(
        private TenantRoleAssignmentService $assignments,
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
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );
        $scope = AuthorizationScope::tenant($tenant->id);
        $this->authorization->require(
            $subject,
            Permission::TenantRolesAssign,
            $scope,
        );
        $membershipId = $this->identifier(
            $args['membershipId'] ?? '',
            'TENANT_MEMBERSHIP_NOT_FOUND',
            'The tenant membership was not found.',
        );
        $roleId = $this->identifier(
            $args['roleId'] ?? '',
            'TENANT_ROLE_NOT_FOUND',
            'The tenant role was not found.',
        );
        $arguments = [
            'tenantId' => $tenant->id,
            'membershipId' => $membershipId,
            'roleId' => $roleId,
            'actorUserId' => $session->actorUserId,
            'requestId' => $this->requestId($request),
            'ipAddress' => $this->ipAddress($request),
            'mayManageOwners' => $this->authorization->isGranted(
                $subject,
                Permission::TenantRolesManage,
                $scope,
            ),
            'effectiveUserId' => $session->effectiveUserIdForAudit(),
        ];

        if ($request->getMethod() === 'PUT') {
            $this->assignments->assign(...$arguments);
        } elseif ($request->getMethod() === 'DELETE') {
            $this->assignments->unassign(...$arguments);
        } else {
            throw new RuntimeException(
                'Unsupported tenant role assignment operation.',
            );
        }

        return $response->withStatus(204);
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
