<?php

declare(strict_types=1);

namespace Sova\Workgroups\Presentation\Http\Action;

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
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;
use Sova\Workgroups\Application\WorkgroupAdministrationService;
use Sova\Workgroups\Application\WorkgroupMemberRoleValidator;
use Sova\Workgroups\Presentation\Http\WorkgroupSerializer;

final readonly class MutateWorkgroupMemberAction
{
    public function __construct(
        private WorkgroupAdministrationService $administration,
        private WorkgroupMemberRoleValidator $validator,
        private WorkgroupSerializer $serializer,
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
        $workgroupId = $this->identifier(
            $args['workgroupId'] ?? '',
            'WORKGROUP_NOT_FOUND',
            'The workgroup was not found.',
        );
        $membershipId = $this->identifier(
            $args['membershipId'] ?? '',
            'TENANT_MEMBERSHIP_NOT_FOUND',
            'The tenant membership was not found.',
        );
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );

        if (!$this->authorization->isGranted(
            $subject,
            Permission::TenantWorkgroupsManage,
            AuthorizationScope::tenant($tenant->id),
        )) {
            $this->authorization->require(
                $subject,
                Permission::WorkgroupMembersManage,
                AuthorizationScope::workgroup($tenant->id, $workgroupId),
            );
        }

        $requestId = $this->requestId($request);
        $ipAddress = $this->ipAddress($request);

        if ($request->getMethod() === 'PUT') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $member = $this->administration->upsertMember(
                $tenant->id,
                $workgroupId,
                $membershipId,
                $this->validator->validate($payload),
                $session->actorUserId,
                $requestId,
                $ipAddress,
            );

            return JsonResponse::write(
                $response,
                ['member' => $this->serializer->serializeMember($member)],
            );
        }

        if ($request->getMethod() === 'DELETE') {
            $this->administration->removeMember(
                $tenant->id,
                $workgroupId,
                $membershipId,
                $session->actorUserId,
                $requestId,
                $ipAddress,
            );

            return $response->withStatus(204);
        }

        throw new RuntimeException('Unsupported workgroup member operation.');
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
                'Workgroup administration requires session and tenant contexts.',
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
