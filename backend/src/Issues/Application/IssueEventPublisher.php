<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

interface IssueEventPublisher
{
    /**
     * Appends a domain event to the transactional outbox. Must be called inside
     * the transaction that changed the issue.
     *
     * @param int                  $sequenceNumber issue version the event describes
     * @param array<string, mixed> $payload
     */
    public function publish(
        string $tenantId,
        string $issueId,
        int $sequenceNumber,
        string $eventName,
        array $payload,
    ): void;
}
