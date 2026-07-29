<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\History\HistoryEntry;
use Sova\Issues\Application\History\HistoryRepository;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Presentation\Http\ActivitySerializer;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * The user-facing activity log of one issue, newest first. It is not the
 * security audit: it explains how the issue evolved and is therefore readable
 * with `issue.view`, while the tamper-evident audit stays behind
 * `tenant.audit.view`.
 */
final readonly class IssueHistoryAction
{
    private const int MAX_ENTRIES = 200;

    public function __construct(
        private HistoryRepository $history,
        private IssueRepository $issues,
        private ActivitySerializer $serializer,
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

        return JsonResponse::write($response, [
            'history' => array_map(
                fn(HistoryEntry $entry): array => $this->serializer
                    ->serializeHistoryEntry($entry),
                $this->history->listForIssue(
                    $issue->tenantId,
                    $issue->id,
                    self::MAX_ENTRIES,
                ),
            ),
        ]);
    }
}
