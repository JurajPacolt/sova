<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Comment;

use DateTimeImmutable;

/**
 * The stored shape of a comment, used for authorisation and lifecycle decisions
 * rather than for display. It carries the project and issue so a comment can
 * never be acted on outside the issue it belongs to.
 */
final readonly class CommentRecord
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $projectId,
        public string $issueId,
        public string $authorMembershipId,
        public string $authorUserId,
        public int $version,
        public bool $deleted,
        public DateTimeImmutable $createdAt,
    ) {}
}
