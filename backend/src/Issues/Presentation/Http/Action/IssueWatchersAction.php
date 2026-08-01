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
use Sova\Issues\Application\Watcher\Watcher;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Who is watching this issue, plus whether the caller is. The list only names
 * active members of the tenant, and reading it needs `issue.view` on the
 * project — so it cannot reveal people outside the caller's project context
 * (webflow §6).
 */
final readonly class IssueWatchersAction
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

        return JsonResponse::write($response, [
            'watchers' => array_map(
                static fn(Watcher $watcher): array => [
                    'membership_id' => $watcher->membershipId,
                    'display_name' => $watcher->displayName,
                    // The automatic rules are visible rather than invisible
                    // magic, so the user can see why they are subscribed.
                    'source' => $watcher->source->value,
                    'since' => $watcher->since->format(DATE_ATOM),
                ],
                $this->watchers->listForIssue($issue->tenantId, $issue->id),
            ),
            'watching' => $membershipId !== null && $this->watchers->isWatching(
                $issue->tenantId,
                $issue->id,
                $membershipId,
            ),
        ]);
    }
}
