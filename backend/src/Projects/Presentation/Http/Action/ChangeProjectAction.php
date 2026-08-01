<?php

declare(strict_types=1);

namespace Sova\Projects\Presentation\Http\Action;

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
use Sova\Projects\Application\ProjectAdministrationService;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Projects\Domain\ProjectVisibility;
use Sova\Projects\Presentation\Http\ProjectSerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class ChangeProjectAction
{
    public function __construct(
        private ProjectAdministrationService $administration,
        private ProjectSerializer $serializer,
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
        $projectId = $this->projectId($args['projectId'] ?? '');
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );
        $this->requireProjectManage($subject, $tenant->id, $projectId);
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $hasStatus = array_key_exists('status', $payload);
        $hasVisibility = array_key_exists('visibility', $payload);

        if ($hasStatus === $hasVisibility || count($payload) !== 1) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PROJECT_CHANGE_INVALID',
                'Change exactly one of: status, visibility.',
                ['body' => ['Change exactly one of: status, visibility.']],
            );
        }

        $project = $hasStatus
            ? $this->administration->changeStatus(
                $tenant->id,
                $projectId,
                $this->status($payload['status'] ?? null),
                $session->actorUserId,
                $this->requestId($request),
                $this->ipAddress($request),
            )
            : $this->administration->changeVisibility(
                $tenant->id,
                $projectId,
                $this->visibility($payload['visibility'] ?? null),
                $session->actorUserId,
                $this->requestId($request),
                $this->ipAddress($request),
            );

        return JsonResponse::write(
            $response,
            ['project' => $this->serializer->serialize($project)],
        );
    }

    private function requireProjectManage(
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
            Permission::ProjectSettingsManage,
            AuthorizationScope::project($tenantId, $projectId),
        );
    }

    private function status(mixed $value): ProjectStatus
    {
        $status = is_string($value) ? ProjectStatus::tryFrom($value) : null;

        if ($status === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PROJECT_STATUS_INVALID',
                'Use one of: ACTIVE, ARCHIVED.',
                ['status' => ['Use one of: ACTIVE, ARCHIVED.']],
            );
        }

        return $status;
    }

    private function visibility(mixed $value): ProjectVisibility
    {
        $visibility = is_string($value)
            ? ProjectVisibility::tryFrom($value)
            : null;

        if ($visibility === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PROJECT_VISIBILITY_INVALID',
                'Use one of: TENANT, PRIVATE.',
                ['visibility' => ['Use one of: TENANT, PRIVATE.']],
            );
        }

        return $visibility;
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

    private function projectId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'PROJECT_NOT_FOUND',
                'The project was not found.',
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
