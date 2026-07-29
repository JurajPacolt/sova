<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Sova\Issues\Application\IssueEventPublisher;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineIssueEventPublisher implements IssueEventPublisher
{
    private const AGGREGATE_TYPE = 'ISSUE';

    public function __construct(private Connection $connection) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    public function publish(
        string $tenantId,
        string $issueId,
        int $sequenceNumber,
        string $eventName,
        array $payload,
    ): void {
        $this->connection->insert('outbox_events', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $issueId,
            'event_name' => $eventName,
            'event_version' => 1,
            // The issue version doubles as the aggregate sequence, so a replay
            // stays ordered and the unique constraint rejects duplicates.
            'sequence_number' => $sequenceNumber,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
