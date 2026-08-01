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
use Sova\Workgroups\Domain\WorkgroupStatus;
use Sova\Workgroups\Presentation\Http\WorkgroupSerializer;

final readonly class ChangeWorkgroupStatusAction
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
        $this->requireWorkgroupManage($subject, $tenant->id, $workgroupId);
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $workgroup = $this->administration->changeStatus(
            $tenant->id,
            $workgroupId,
            $this->status($payload['status'] ?? null),
            $session->actorUserId,
            $this->requestId($request),
            $this->ipAddress($request),
        );

        return JsonResponse::write(
            $response,
            ['workgroup' => $this->serializer->serialize($workgroup)],
        );
    }

    private function requireWorkgroupManage(
        AuthorizationSubject $subject,
        string $tenantId,
        string $workgroupId,
    ): void {
        if ($this->authorization->isGranted(
            $subject,
            Permission::TenantWorkgroupsManage,
            AuthorizationScope::tenant($tenantId),
        )) {
            return;
        }

        $this->authorization->require(
            $subject,
            Permission::WorkgroupManage,
            AuthorizationScope::workgroup($tenantId, $workgroupId),
        );
    }

    private function status(mixed $value): WorkgroupStatus
    {
        $status = is_string($value) ? WorkgroupStatus::tryFrom($value) : null;

        if ($status === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WORKGROUP_STATUS_INVALID',
                'Use one of: ACTIVE, ARCHIVED.',
                ['status' => ['Use one of: ACTIVE, ARCHIVED.']],
            );
        }

        return $status;
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
