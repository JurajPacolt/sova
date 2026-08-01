<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Background;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Tenancy\Application\Invitation\InvitationMailer;
use Sova\Tenancy\Application\Invitation\InvitationRepository;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineInvitationPublisher;
use Throwable;

final readonly class InvitationOutboxWorker
{
    private int $maxAttempts;

    public function __construct(
        private Connection $connection,
        private SensitivePayloadCipher $cipher,
        private InvitationRepository $invitations,
        private OneTimeTokenGenerator $tokenGenerator,
        private InvitationMailer $mailer,
        Settings $settings,
    ) {
        $this->maxAttempts = $settings->int('outbox.max_attempts');

        if ($this->maxAttempts <= 0) {
            throw new InvalidArgumentException(
                'OUTBOX_MAX_ATTEMPTS must be positive.',
            );
        }
    }

    public function runBatch(int $limit = 10): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException(
                'The outbox batch limit must be between 1 and 100.',
            );
        }

        $attempted = 0;

        while ($attempted < $limit && $this->processNext()) {
            ++$attempted;
        }

        return $attempted;
    }

    private function processNext(): bool
    {
        $eventId = null;

        try {
            return $this->connection->transactional(function () use (&$eventId): bool {
                $row = $this->connection->fetchAssociative(
                    <<<'SQL'
                        SELECT
                            event.id,
                            sensitive.key_id,
                            sensitive.ciphertext,
                            sensitive.expires_at
                        FROM outbox_events event
                        INNER JOIN outbox_sensitive_payloads sensitive
                            ON sensitive.event_id = event.id
                        WHERE event.event_name = :event_name
                            AND event.processed_at IS NULL
                            AND event.failed_at IS NULL
                            AND event.available_at <= CURRENT_TIMESTAMP
                        ORDER BY event.created_at, event.id
                        FOR UPDATE OF event, sensitive SKIP LOCKED
                        LIMIT 1
                        SQL,
                    ['event_name' => DoctrineInvitationPublisher::EVENT_NAME],
                );

                if ($row === false) {
                    return false;
                }

                $eventId = $this->stringValue($row, 'id');
                $expiresAt = new DateTimeImmutable(
                    $this->stringValue($row, 'expires_at'),
                );
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                if ($expiresAt <= $now) {
                    $this->markExpired($eventId);

                    return true;
                }

                $payload = $this->cipher->decrypt(
                    $this->stringValue($row, 'key_id'),
                    $this->stringValue($row, 'ciphertext'),
                );
                $invitationId = $payload['invitation_id'] ?? null;
                $plainTextToken = $payload['token'] ?? null;

                if (
                    !is_string($invitationId)
                    || !is_string($plainTextToken)
                    || !$this->tokenGenerator->hasValidFormat($plainTextToken)
                ) {
                    throw new RuntimeException(
                        'The invitation delivery payload is invalid.',
                    );
                }

                $invitation = $this->invitations->findUsableByTokenHash(
                    $this->tokenGenerator->hash($plainTextToken),
                    true,
                );

                if ($invitation !== null) {
                    if (!hash_equals($invitation->id, $invitationId)) {
                        throw new RuntimeException(
                            'The invitation delivery payload does not match its record.',
                        );
                    }

                    $this->mailer->send($invitation, $plainTextToken);
                }

                $this->markProcessed($eventId);

                return true;
            });
        } catch (Throwable) {
            if ($eventId === null) {
                throw new RuntimeException(
                    'An invitation outbox event failed before identification.',
                );
            }

            $this->recordFailure($eventId);

            return true;
        }
    }

    private function markProcessed(string $eventId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE outbox_events
                SET processed_at = CURRENT_TIMESTAMP,
                    last_error = NULL
                WHERE id = :event_id
                SQL,
            ['event_id' => $eventId],
        );
        $this->purgeSensitivePayload($eventId);
    }

    private function markExpired(string $eventId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE outbox_events
                SET failed_at = CURRENT_TIMESTAMP,
                    last_error = 'INVITATION_DELIVERY_REQUEST_EXPIRED'
                WHERE id = :event_id
                SQL,
            ['event_id' => $eventId],
        );
        $this->purgeSensitivePayload($eventId);
    }

    private function recordFailure(string $eventId): void
    {
        $attemptCount = $this->connection->fetchOne(
            <<<'SQL'
                SELECT attempt_count
                FROM outbox_events
                WHERE id = :event_id
                    AND processed_at IS NULL
                    AND failed_at IS NULL
                SQL,
            ['event_id' => $eventId],
        );

        if (!is_int($attemptCount) && !is_string($attemptCount)) {
            return;
        }

        $nextAttempt = (int) $attemptCount + 1;

        if ($nextAttempt >= $this->maxAttempts) {
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE outbox_events
                    SET attempt_count = :attempt_count,
                        failed_at = CURRENT_TIMESTAMP,
                        last_error = 'INVITATION_EMAIL_DELIVERY_FAILED'
                    WHERE id = :event_id
                        AND processed_at IS NULL
                        AND failed_at IS NULL
                    SQL,
                [
                    'attempt_count' => $nextAttempt,
                    'event_id' => $eventId,
                ],
            );
            $this->purgeSensitivePayload($eventId);

            return;
        }

        $backoffSeconds = min(
            3_600,
            30 * (2 ** min($nextAttempt - 1, 6)),
        );
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE outbox_events
                    SET attempt_count = :attempt_count,
                        available_at = CURRENT_TIMESTAMP + INTERVAL '%d seconds',
                        last_error = 'INVITATION_EMAIL_DELIVERY_FAILED'
                    WHERE id = :event_id
                        AND processed_at IS NULL
                        AND failed_at IS NULL
                    SQL,
                $backoffSeconds,
            ),
            [
                'attempt_count' => $nextAttempt,
                'event_id' => $eventId,
            ],
        );
    }

    private function purgeSensitivePayload(string $eventId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE outbox_sensitive_payloads
                SET ciphertext = 'PURGED',
                    consumed_at = CURRENT_TIMESTAMP
                WHERE event_id = :event_id
                    AND consumed_at IS NULL
                SQL,
            ['event_id' => $eventId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a string.',
                $key,
            ));
        }

        return $value;
    }
}
