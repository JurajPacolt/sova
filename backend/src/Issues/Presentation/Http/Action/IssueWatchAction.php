<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Starts or stops the caller's own subscription — one clear action each way
 * (webflow §6). A member manages only their own watch; subscribing somebody
 * else is not part of the MVP, so there is no identifier in the path to abuse.
 *
 * Both verbs are idempotent, and stopping is stored rather than deleted so the
 * automatic rules cannot resubscribe someone who opted out.
 */
final readonly class IssueWatchAction
{
    public function __construct(
        private WatcherRepository $watchers,
        private IssueRepository $issues,
        private AuthorizationService $authorization,
        private IssueRequestContext $context,
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
        $resolved = $this->context->resolve(
            $request,
            $args['issueId'] ?? '',
            $this->issues,
        );
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        $membershipId = $resolved->actorMembershipId;

        if ($membershipId === null) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'WATCHER_MEMBERSHIP_REQUIRED',
                'Only a tenant member can watch an issue.',
            );
        }

        $watching = $request->getMethod() === 'PUT';
        $this->watchers->setWatching(
            $issue->tenantId,
            $issue->projectId,
            $issue->id,
            $membershipId,
            $watching,
        );

        return JsonResponse::write($response, ['watching' => $watching]);
    }
}
