<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * One entry of the project configuration history log (§14): what changed, at
 * which revision, by whom and when.
 */
final readonly class ConfigurationHistoryEntry
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public int $revision,
        public string $eventType,
        public ?string $workflowId,
        public ?string $workflowVersionId,
        public ?string $actorUserId,
        public array $metadata,
        public string $createdAt,
    ) {}
}
