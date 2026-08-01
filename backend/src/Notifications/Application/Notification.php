<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use DateTimeImmutable;

/**
 * One entry of a member's in-app inbox. The payload carries only what the list
 * needs to render — the issue key, its title and the actor's name — so opening
 * the notification centre never reveals more than the issue list already would.
 */
final readonly class Notification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $projectId,
        public ?string $issueId,
        public ?string $actorUserId,
        public ?string $actorDisplayName,
        public array $payload,
        public ?DateTimeImmutable $readAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
