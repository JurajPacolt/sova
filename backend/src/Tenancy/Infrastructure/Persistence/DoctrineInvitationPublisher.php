<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Tenancy\Application\Invitation\InvitationPublisher;

final readonly class DoctrineInvitationPublisher implements InvitationPublisher
{
    public const EVENT_NAME = 'TENANT_INVITATION_DELIVERY_REQUESTED';

    public function __construct(
        private Connection $connection,
        private SensitivePayloadCipher $cipher,
    ) {}

    public function publish(
        string $invitationId,
        string $tenantId,
        string $plainTextToken,
        DateTimeImmutable $deliveryExpiresAt,
    ): void {
        $eventId = (string) UuidV7::generate();
        $encrypted = $this->cipher->encrypt([
            'invitation_id' => $invitationId,
            'token' => $plainTextToken,
        ]);
        $sequenceNumber = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(MAX(sequence_number), 0) + 1
                FROM outbox_events
                WHERE aggregate_type = 'TENANT_INVITATION'
                    AND aggregate_id = :invitation_id
                SQL,
            ['invitation_id' => $invitationId],
        );

        if (!is_int($sequenceNumber) && !is_string($sequenceNumber)) {
            throw new \RuntimeException(
                'The next invitation delivery sequence could not be determined.',
            );
        }

        $this->connection->insert('outbox_events', [
            'id' => $eventId,
            'tenant_id' => $tenantId,
            'aggregate_type' => 'TENANT_INVITATION',
            'aggregate_id' => $invitationId,
            'event_name' => self::EVENT_NAME,
            'event_version' => 1,
            'sequence_number' => (int) $sequenceNumber,
            'payload' => '{}',
        ]);
        $this->connection->insert('outbox_sensitive_payloads', [
            'event_id' => $eventId,
            'key_id' => $encrypted->keyId,
            'ciphertext' => $encrypted->ciphertext,
            'expires_at' => $deliveryExpiresAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
