<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Link;

use Sova\Issues\Application\IssueDetails;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Domain\Link\IssueLinkType;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Creating and removing links between issues.
 *
 * Both endpoints are always loaded through the tenant of the route, so a link
 * cannot cross tenants — the schema keeps a single `tenant_id` for the pair, so
 * it is not even representable. The target must additionally be an issue the
 * actor may see, otherwise linking would confirm the existence of work in a
 * project they have no access to.
 */
final readonly class IssueLinkService
{
    public function __construct(
        private IssueLinkRepository $links,
        private IssueRepository $issues,
    ) {}

    /**
     * @param list<string> $visibleProjectIds
     *
     * @return list<IssueLink>
     */
    public function listForIssue(
        string $tenantId,
        string $issueId,
        array $visibleProjectIds,
    ): array {
        return $this->links->listForIssue($tenantId, $issueId, $visibleProjectIds);
    }

    /**
     * @param list<string> $visibleProjectIds
     */
    public function create(
        IssueDetails $source,
        string $targetIssueId,
        IssueLinkType $type,
        array $visibleProjectIds,
        string $actorUserId,
    ): string {
        if ($targetIssueId === $source->id) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ISSUE_LINK_SELF',
                'An issue cannot be linked to itself.',
            );
        }

        $target = $this->issues->find($source->tenantId, $targetIssueId);

        // Out of tenant, missing, or in a project the actor may not see — all
        // three answer the same way, so a link attempt cannot be used to probe.
        if ($target === null || !in_array($target->projectId, $visibleProjectIds, true)) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'ISSUE_NOT_FOUND',
                'The issue was not found.',
            );
        }

        if ($this->links->pairExists($source->tenantId, $source->id, $target->id, $type)) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ISSUE_LINK_EXISTS',
                $type->isSymmetric()
                    ? 'These issues are already linked with this type.'
                    : 'These issues already carry this link in one of the two directions.',
            );
        }

        $linkId = (string) UuidV7::generate();

        $this->links->create(
            $source->tenantId,
            $linkId,
            $source->id,
            $target->id,
            $type,
            $actorUserId,
        );

        $this->record($source, $target->id, $type, 'ISSUE_LINK_ADDED', $actorUserId);

        return $linkId;
    }

    public function delete(
        IssueDetails $issue,
        IssueLinkRecord $link,
        string $actorUserId,
    ): void {
        $this->links->delete($issue->tenantId, $link->id);

        $other = $link->sourceIssueId === $issue->id
            ? $link->targetIssueId
            : $link->sourceIssueId;

        $this->record($issue, $other, $link->type, 'ISSUE_LINK_REMOVED', $actorUserId);
    }

    private function record(
        IssueDetails $issue,
        string $otherIssueId,
        IssueLinkType $type,
        string $eventType,
        string $actorUserId,
    ): void {
        $this->issues->recordHistory(
            $issue->tenantId,
            $issue->projectId,
            $issue->id,
            $issue->version,
            $eventType,
            $actorUserId,
            null,
            null,
            null,
            ['link_type' => $type->value, 'other_issue_id' => $otherIssueId],
            // Linking annotates the issue; it does not change the issue itself,
            // so it must not bump the version or claim its history slot.
            false,
        );
    }
}
