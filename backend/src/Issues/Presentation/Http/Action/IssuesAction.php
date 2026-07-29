<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

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
use Sova\Issues\Application\CreateIssueInputValidator;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\IssueService;
use Sova\Issues\Presentation\Http\IssueSerializer;
use Sova\Projects\Application\ProjectDetails;
use Sova\Projects\Application\ProjectRepository;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class IssuesAction
{
    private const MAX_ISSUES = 100;

    public function __construct(
        private IssueService $issues,
        private IssueRepository $repository,
        private ProjectRepository $projects,
        private CreateIssueInputValidator $validator,
        private IssueSerializer $serializer,
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
        $project = $this->project($tenant->id, $args['projectId'] ?? '');
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );
        $scope = AuthorizationScope::project($tenant->id, $project->id);

        if ($request->getMethod() === 'GET') {
            $this->authorization->require($subject, Permission::IssueView, $scope);

            return JsonResponse::write($response, [
                'issues' => array_map(
                    $this->serializer->serialize(...),
                    $this->repository->listForProject(
                        $tenant->id,
                        $project->id,
                        self::MAX_ISSUES,
                    ),
                ),
            ]);
        }

        if ($request->getMethod() === 'POST') {
            $this->authorization->require($subject, Permission::IssueCreate, $scope);

            if ($project->status !== ProjectStatus::Active) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'PROJECT_ARCHIVED',
                    'An archived project does not accept new issues.',
                );
            }

            $body = $request->getParsedBody();
            $issue = $this->issues->create(
                $tenant->id,
                $project->id,
                $project->code,
                $this->validator->validate(is_array($body) ? $body : []),
                $session->actorUserId,
                $this->reporterMembershipId($tenant),
            );

            return JsonResponse::write(
                $response,
                ['issue' => $this->serializer->serialize($issue)],
                201,
            );
        }

        throw new RuntimeException('Unsupported issue collection operation.');
    }

    /**
     * The reporter is always the effective member, never the impersonating
     * actor; a superadmin without membership cannot report an issue.
     */
    private function reporterMembershipId(AccessibleTenant $tenant): string
    {
        if ($tenant->membershipId === null) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ISSUE_REPORTER_MEMBERSHIP_REQUIRED',
                'Reporting an issue requires an active membership in this tenant.',
            );
        }

        return $tenant->membershipId;
    }

    private function project(string $tenantId, string $projectId): ProjectDetails
    {
        try {
            $identifier = (string) UuidV7::fromString($projectId);
        } catch (InvalidArgumentException) {
            throw $this->projectNotFound();
        }

        return $this->projects->findForTenant($tenantId, $identifier)
            ?? throw $this->projectNotFound();
    }

    private function projectNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'PROJECT_NOT_FOUND',
            'The project was not found.',
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
                'Issue tracking requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }
}
