<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Sova\Identity\Application\Token\UserActionRequestPublisher;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineUserActionRequestPublisher implements UserActionRequestPublisher
{
    public const PASSWORD_RESET_EVENT = 'IDENTITY_PASSWORD_RESET_REQUESTED';
    public const EMAIL_VERIFICATION_EVENT = 'IDENTITY_EMAIL_VERIFICATION_REQUESTED';

    public function __construct(
        private Connection $connection,
        private SensitivePayloadCipher $cipher,
    ) {}

    public function publish(
        OneTimeTokenPurpose $purpose,
        string $normalizedEmail,
        DateTimeImmutable $expiresAt,
    ): void {
        $eventId = (string) UuidV7::generate();
        $encrypted = $this->cipher->encrypt([
            'normalized_email' => $normalizedEmail,
        ]);
        $eventName = match ($purpose) {
            OneTimeTokenPurpose::PasswordReset => self::PASSWORD_RESET_EVENT,
            OneTimeTokenPurpose::EmailVerification => self::EMAIL_VERIFICATION_EVENT,
        };

        $this->connection->insert('outbox_events', [
            'id' => $eventId,
            'aggregate_type' => 'USER_ACTION_REQUEST',
            'aggregate_id' => $eventId,
            'event_name' => $eventName,
            'event_version' => 1,
            'sequence_number' => 1,
            'payload' => '{}',
        ]);
        $this->connection->insert('outbox_sensitive_payloads', [
            'event_id' => $eventId,
            'key_id' => $encrypted->keyId,
            'ciphertext' => $encrypted->ciphertext,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
