<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Background;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\EmailVerification\EmailVerificationMailer;
use Sova\Identity\Application\PasswordRecovery\PasswordResetMailer;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Application\Token\OneTimeTokenRepository;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Identity\Infrastructure\Persistence\DoctrineUserActionRequestPublisher;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Throwable;

final readonly class IdentityEmailOutboxWorker
{
    private int $passwordResetTtlSeconds;
    private int $emailVerificationTtlSeconds;
    private int $maxAttempts;

    public function __construct(
        private Connection $connection,
        private SensitivePayloadCipher $cipher,
        private UserCredentialsRepository $users,
        private OneTimeTokenRepository $tokens,
        private OneTimeTokenGenerator $tokenGenerator,
        private PasswordResetMailer $passwordResetMailer,
        private EmailVerificationMailer $emailVerificationMailer,
        Settings $settings,
    ) {
        $this->passwordResetTtlSeconds = $this->positiveSetting(
            $settings,
            'auth.password_reset_ttl_seconds',
        );
        $this->emailVerificationTtlSeconds = $this->positiveSetting(
            $settings,
            'auth.email_verification_ttl_seconds',
        );
        $this->maxAttempts = $this->positiveSetting(
            $settings,
            'outbox.max_attempts',
        );
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
        $eventName = null;

        try {
            return $this->connection->transactional(function () use (
                &$eventId,
                &$eventName,
            ): bool {
                $row = $this->connection->fetchAssociative(
                    <<<'SQL'
                        SELECT
                            event.id,
                            event.event_name,
                            event.attempt_count,
                            sensitive.key_id,
                            sensitive.ciphertext,
                            sensitive.expires_at
                        FROM outbox_events event
                        INNER JOIN outbox_sensitive_payloads sensitive
                            ON sensitive.event_id = event.id
                        WHERE event.event_name IN (
                                :password_reset_event,
                                :email_verification_event
                            )
                            AND event.processed_at IS NULL
                            AND event.failed_at IS NULL
                            AND event.available_at <= CURRENT_TIMESTAMP
                        ORDER BY event.created_at, event.id
                        FOR UPDATE OF event, sensitive SKIP LOCKED
                        LIMIT 1
                    SQL,
                    [
                        'password_reset_event' => DoctrineUserActionRequestPublisher::PASSWORD_RESET_EVENT,
                        'email_verification_event' => DoctrineUserActionRequestPublisher::EMAIL_VERIFICATION_EVENT,
                    ],
                );

                if ($row === false) {
                    return false;
                }

                $eventId = $this->stringValue($row, 'id');
                $eventName = $this->stringValue($row, 'event_name');
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
                $normalizedEmail = $payload['normalized_email'] ?? null;

                if (
                    !is_string($normalizedEmail)
                    || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false
                    || strtolower(trim($normalizedEmail)) !== $normalizedEmail
                ) {
                    throw new RuntimeException(
                        'The identity email payload is invalid.',
                    );
                }

                $user = $this->users->findByNormalizedEmail($normalizedEmail);

                if (
                    $user !== null
                    && $this->isEligible($eventName, $user->status)
                ) {
                    $token = $this->tokenGenerator->generate();
                    $tokenExpiresAt = $now->modify(sprintf(
                        '+%d seconds',
                        $this->tokenTtlSeconds($eventName),
                    ));
                    $this->tokens->replaceActive(
                        tokenId: (string) UuidV7::generate(),
                        userId: $user->id,
                        purpose: $this->tokenPurpose($eventName),
                        tokenHash: $token->hash(),
                        expiresAt: $tokenExpiresAt,
                    );
                    match ($eventName) {
                        DoctrineUserActionRequestPublisher::PASSWORD_RESET_EVENT =>
                            $this->passwordResetMailer->send(
                                $user,
                                $token,
                                $tokenExpiresAt,
                            ),
                        DoctrineUserActionRequestPublisher::EMAIL_VERIFICATION_EVENT =>
                            $this->emailVerificationMailer->send(
                                $user,
                                $token,
                                $tokenExpiresAt,
                            ),
                        default => throw new RuntimeException(
                            'The identity email event is unsupported.',
                        ),
                    };
                }

                $this->markProcessed($eventId);

                return true;
            });
        } catch (Throwable) {
            if ($eventId === null || $eventName === null) {
                throw new RuntimeException(
                    'An outbox event failed before it could be identified.',
                );
            }

            $this->recordFailure($eventId, $eventName);

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
                    last_error = 'IDENTITY_EMAIL_REQUEST_EXPIRED'
                WHERE id = :event_id
                SQL,
            ['event_id' => $eventId],
        );
        $this->purgeSensitivePayload($eventId);
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

    private function recordFailure(string $eventId, string $eventName): void
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
        $failureReason = match ($eventName) {
            DoctrineUserActionRequestPublisher::PASSWORD_RESET_EVENT =>
                'PASSWORD_RESET_EMAIL_DELIVERY_FAILED',
            DoctrineUserActionRequestPublisher::EMAIL_VERIFICATION_EVENT =>
                'EMAIL_VERIFICATION_DELIVERY_FAILED',
            default => 'IDENTITY_EMAIL_DELIVERY_FAILED',
        };

        if ($nextAttempt >= $this->maxAttempts) {
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE outbox_events
                    SET attempt_count = :attempt_count,
                        failed_at = CURRENT_TIMESTAMP,
                        last_error = :last_error
                    WHERE id = :event_id
                        AND processed_at IS NULL
                        AND failed_at IS NULL
                    SQL,
                [
                    'attempt_count' => $nextAttempt,
                    'last_error' => $failureReason,
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
                        last_error = :last_error
                    WHERE id = :event_id
                        AND processed_at IS NULL
                        AND failed_at IS NULL
                    SQL,
                $backoffSeconds,
            ),
            [
                'attempt_count' => $nextAttempt,
                'last_error' => $failureReason,
                'event_id' => $eventId,
            ],
        );
    }

    private function isEligible(
        string $eventName,
        UserStatus $status,
    ): bool {
        return match ($eventName) {
            DoctrineUserActionRequestPublisher::PASSWORD_RESET_EVENT =>
                $status === UserStatus::Active,
            DoctrineUserActionRequestPublisher::EMAIL_VERIFICATION_EVENT =>
                $status === UserStatus::PendingVerification,
            default => false,
        };
    }

    private function tokenPurpose(string $eventName): OneTimeTokenPurpose
    {
        return match ($eventName) {
            DoctrineUserActionRequestPublisher::PASSWORD_RESET_EVENT =>
                OneTimeTokenPurpose::PasswordReset,
            DoctrineUserActionRequestPublisher::EMAIL_VERIFICATION_EVENT =>
                OneTimeTokenPurpose::EmailVerification,
            default => throw new RuntimeException(
                'The identity email event is unsupported.',
            ),
        };
    }

    private function tokenTtlSeconds(string $eventName): int
    {
        return match ($eventName) {
            DoctrineUserActionRequestPublisher::PASSWORD_RESET_EVENT =>
                $this->passwordResetTtlSeconds,
            DoctrineUserActionRequestPublisher::EMAIL_VERIFICATION_EVENT =>
                $this->emailVerificationTtlSeconds,
            default => throw new RuntimeException(
                'The identity email event is unsupported.',
            ),
        };
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

    private function positiveSetting(Settings $settings, string $key): int
    {
        $value = $settings->int($key);

        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Setting "%s" must be positive.',
                $key,
            ));
        }

        return $value;
    }
}
