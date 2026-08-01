<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Comment;

/**
 * A resolved mention. The display name is a convenience for rendering; the
 * membership identifier is the stable reference the comment source carries.
 */
final readonly class CommentMention
{
    public function __construct(
        public string $membershipId,
        public ?string $displayName,
    ) {}
}
