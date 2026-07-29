<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Sova\ProjectConfiguration\Application\ConfigurationEventPublisher;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineConfigurationEventPublisher implements ConfigurationEventPublisher
{
    private const AGGREGATE_TYPE = 'PROJECT_CONFIGURATION';

    public function __construct(private Connection $connection) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    public function publish(
        string $tenantId,
        string $projectId,
        int $revision,
        string $eventName,
        array $payload,
    ): void {
        $this->connection->insert('outbox_events', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $projectId,
            'event_name' => $eventName,
            'event_version' => 1,
            // The configuration revision doubles as the aggregate sequence, so a
            // replay stays ordered and the unique constraint rejects duplicates.
            'sequence_number' => $revision,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
