<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineAuthenticationEventRecorder implements AuthenticationEventRecorder
{
    public function __construct(private Connection $connection) {}

    public function record(
        string $eventType,
        string $outcome,
        string $reasonCode,
        string $requestId,
        ?string $ipAddress,
        ?string $userId = null,
        ?string $sessionId = null,
    ): void {
        $this->connection->insert('authentication_events', [
            'id' => (string) UuidV7::generate(),
            'user_id' => $userId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
        ]);
    }
}
