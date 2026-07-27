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
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;
use Sova\Workgroups\Application\WorkgroupAdministrationService;
use Sova\Workgroups\Presentation\Http\WorkgroupSerializer;

final readonly class WorkgroupMembersAction
{
    public function __construct(
        private WorkgroupAdministrationService $administration,
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
        $workgroupId = $this->workgroupId($args['workgroupId'] ?? '');
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
                Permission::WorkgroupView,
                AuthorizationScope::workgroup($tenant->id, $workgroupId),
            );
        }

        $members = $this->administration->listMembers(
            $tenant->id,
            $workgroupId,
        );

        return JsonResponse::write($response, [
            'members' => array_map(
                $this->serializer->serializeMember(...),
                $members,
            ),
        ]);
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

    private function workgroupId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'WORKGROUP_NOT_FOUND',
                'The workgroup was not found.',
            );
        }
    }
}
