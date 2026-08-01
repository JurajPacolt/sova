<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * Publishes project configuration domain events to the transactional outbox.
 * Keyed on the project as the aggregate and the configuration revision as the
 * monotonic sequence, so a replay stays ordered.
 */
interface ConfigurationEventPublisher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function publish(
        string $tenantId,
        string $projectId,
        int $revision,
        string $eventName,
        array $payload,
    ): void;
}
