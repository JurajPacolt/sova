<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Link;

use Sova\Issues\Domain\Link\IssueLinkType;

interface IssueLinkRepository
{
    /**
     * Links of one issue in both directions, restricted to the projects the
     * reader may see. A link whose other end is out of reach is omitted
     * entirely — returning it would leak the key and title of an issue the
     * caller has no access to.
     *
     * @param list<string> $visibleProjectIds
     *
     * @return list<IssueLink>
     */
    public function listForIssue(
        string $tenantId,
        string $issueId,
        array $visibleProjectIds,
    ): array;

    public function find(string $tenantId, string $linkId): ?IssueLinkRecord;

    /**
     * True when the same pair already carries this type in either direction.
     * The mirror counts: "A blocks B" together with "B blocks A" is
     * contradictory, and a second "relates to" is redundant.
     */
    public function pairExists(
        string $tenantId,
        string $sourceIssueId,
        string $targetIssueId,
        IssueLinkType $type,
    ): bool;

    public function create(
        string $tenantId,
        string $linkId,
        string $sourceIssueId,
        string $targetIssueId,
        IssueLinkType $type,
        string $createdByUserId,
    ): void;

    public function delete(string $tenantId, string $linkId): void;
}
