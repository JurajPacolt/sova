<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use InvalidArgumentException;
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
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Application\Membership\TenantMembershipLifecycleService;
use Sova\Tenancy\Application\Membership\TenantMembershipStatusValidator;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;
use Sova\Tenancy\Presentation\Http\TenantMembershipSerializer;

final readonly class ChangeTenantMembershipStatusAction
{
    public function __construct(
        private TenantMembershipLifecycleService $lifecycle,
        private TenantMembershipStatusValidator $validator,
        private TenantMembershipSerializer $serializer,
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
        $this->authorization->require(
            $subject,
            Permission::TenantMembersManage,
            $scope,
        );
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $membership = $this->lifecycle->changeStatus(
            tenantId: $tenant->id,
            membershipId: $this->membershipId(
                $args['membershipId'] ?? '',
            ),
            targetStatus: $this->validator->validate($payload),
            actorUserId: $session->actorUserId,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
            mayManageOwners: $this->authorization->isGranted(
                $subject,
                Permission::TenantRolesManage,
                $scope,
            ),
            effectiveUserId: $session->effectiveUserIdForAudit(),
        );

        return JsonResponse::write(
            $response,
            ['membership' => $this->serializer->serialize($membership)],
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
                'Membership lifecycle requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }

    private function membershipId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'TENANT_MEMBERSHIP_NOT_FOUND',
                'The tenant membership was not found.',
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
