<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineSecurityAuditRecorder implements SecurityAuditRecorder
{
    public function __construct(private Connection $connection) {}

    /**
     * @param array<string, bool|int|string|null> $metadata
     *
     * @throws JsonException
     */
    public function record(
        string $eventType,
        string $outcome,
        string $reasonCode,
        string $requestId,
        string $actorUserId,
        ?string $tenantId = null,
        ?string $effectiveUserId = null,
        ?string $ipAddress = null,
        array $metadata = [],
    ): void {
        $this->connection->insert('security_audit_events', [
            'id' => (string) UuidV7::generate(),
            'actor_user_id' => $actorUserId,
            'effective_user_id' => $effectiveUserId,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'metadata' => json_encode(
                (object) $metadata,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        ]);
    }
}
