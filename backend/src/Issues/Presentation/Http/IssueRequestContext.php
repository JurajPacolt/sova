<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\TransitionActor;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Shared resolution for the tenant-level issue routes: an issue is always
 * loaded through the tenant of the route, so a foreign identifier reads as
 * missing instead of confirming that it exists elsewhere.
 */
final readonly class IssueRequestContext
{
    public function __construct(private AuthorizationService $authorization) {}

    public function resolve(
        ServerRequestInterface $request,
        string $issueId,
        IssueRepository $issues,
    ): ResolvedIssue {
        [$session, $tenant] = $this->contexts($request);
        $issue = $issues->find($tenant->id, $this->identifier($issueId));

        if ($issue === null) {
            throw $this->issueNotFound();
        }

        return new ResolvedIssue(
            $session,
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            $issue,
            $tenant->membershipId,
        );
    }

    /**
     * Builds the actor a transition's rules are evaluated for. "Manager" is the
     * project's issue-assignment permission, so a team lead who can reassign
     * work also satisfies an `assignee_or_manager` condition.
     */
    public function transitionActor(
        AuthorizationSubject $subject,
        string $tenantId,
        string $projectId,
        ?string $actorMembershipId,
    ): TransitionActor {
        return new TransitionActor(
            $actorMembershipId,
            $this->authorization->isGranted(
                $subject,
                Permission::IssueAssign,
                AuthorizationScope::project($tenantId, $projectId),
            ),
        );
    }

    /**
     * Builds the callable the issue service uses to authorize a transition:
     * `issue.transition` on the project plus any extra permission the specific
     * transition demands. An unknown extra code fails closed.
     *
     * @return callable(?string): bool
     */
    public function transitionPermissionCheck(
        AuthorizationSubject $subject,
        string $tenantId,
        string $projectId,
    ): callable {
        $scope = AuthorizationScope::project($tenantId, $projectId);

        return function (?string $permissionCode) use ($subject, $scope): bool {
            if (!$this->authorization->isGranted(
                $subject,
                Permission::IssueTransition,
                $scope,
            )) {
                return false;
            }

            if ($permissionCode === null) {
                return true;
            }

            $extra = Permission::tryFrom($permissionCode);

            return $extra !== null
                && $this->authorization->isGranted($subject, $extra, $scope);
        };
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

    private function identifier(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw $this->issueNotFound();
        }
    }

    private function issueNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'ISSUE_NOT_FOUND',
            'The issue was not found.',
        );
    }
}
