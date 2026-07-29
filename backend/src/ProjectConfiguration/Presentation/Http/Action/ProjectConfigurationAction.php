<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http\Action;

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
use Sova\ProjectConfiguration\Application\ProjectConfigurationRepository;
use Sova\ProjectConfiguration\Application\WorkflowConfigurationRepository;
use Sova\ProjectConfiguration\Presentation\Http\ConfigurationSerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class ProjectConfigurationAction
{
    public function __construct(
        private ProjectConfigurationRepository $configuration,
        private WorkflowConfigurationRepository $workflows,
        private ConfigurationSerializer $serializer,
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

        if (!$this->authorization->isGranted(
            $subject,
            Permission::TenantProjectsManage,
            AuthorizationScope::tenant($tenant->id),
        )) {
            $this->authorization->require(
                $subject,
                Permission::ProjectView,
                AuthorizationScope::project($tenant->id, $projectId),
            );
        }

        return JsonResponse::write($response, [
            'revision' => $this->workflows->configurationRevision($tenant->id, $projectId),
            'issue_types' => array_map(
                $this->serializer->serializeIssueType(...),
                $this->configuration->listIssueTypes($tenant->id, $projectId),
            ),
            'statuses' => array_map(
                $this->serializer->serializeStatus(...),
                $this->configuration->listStatuses($tenant->id, $projectId),
            ),
            'workflows' => array_map(
                $this->serializer->serializeWorkflow(...),
                $this->workflows->listWorkflows($tenant->id, $projectId),
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
                'Project configuration requires session and tenant contexts.',
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
}
