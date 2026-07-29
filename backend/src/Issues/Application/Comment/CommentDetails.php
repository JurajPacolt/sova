<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Comment;

use DateTimeImmutable;

/**
 * A comment as the activity stream shows it. A deleted comment keeps its place
 * and its author so the discussion still reads correctly, but carries no body
 * and no mentions — the neutral "comment removed" placeholder of the webflow.
 */
final readonly class CommentDetails
{
    /**
     * @param list<CommentMention> $mentions
     */
    public function __construct(
        public string $id,
        public string $issueId,
        public string $authorMembershipId,
        public ?string $authorDisplayName,
        public ?string $body,
        public int $version,
        public bool $deleted,
        public array $mentions,
        public ?DateTimeImmutable $editedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
