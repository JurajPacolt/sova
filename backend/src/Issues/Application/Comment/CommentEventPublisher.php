<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Comment;

/**
 * Publishes comment events to the transactional outbox so notifications are
 * delivered by the worker rather than inside the request. The comment's version
 * is the aggregate sequence, which makes a replay ordered and lets the unique
 * constraint reject a duplicate.
 */
interface CommentEventPublisher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function publish(
        string $tenantId,
        string $commentId,
        int $sequenceNumber,
        string $eventName,
        array $payload,
    ): void;
}
