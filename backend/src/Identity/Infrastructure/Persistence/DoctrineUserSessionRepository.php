<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\SessionSummary;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Application\Impersonation\ImpersonationRepository;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class DoctrineUserSessionRepository implements UserSessionRepository
{
    private bool $superadminMfaRequired;

    public function __construct(
        private Connection $connection,
        private ImpersonationRepository $impersonations,
        Settings $settings,
    ) {
        $this->superadminMfaRequired = $settings->string(
            'app.environment',
            'production',
        ) === 'production';
    }

    public function create(
        string $sessionId,
        string $userId,
        string $tokenHash,
        string $csrfTokenHash,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
        ?DateTimeImmutable $mfaVerifiedAt,
    ): void {
        $this->connection->insert('user_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'csrf_token_hash' => $csrfTokenHash,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            'mfa_verified_at' => $mfaVerifiedAt?->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function findActiveByTokenHash(string $tokenHash): ?SessionContext
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    sessions.id AS session_id,
                    sessions.user_id,
                    sessions.csrf_token_hash,
                    users.email,
                    users.display_name,
                    users.preferred_locale,
                    sessions.mfa_verified_at,
                    mfa.enabled_at AS mfa_enabled_at,
                    COALESCE(jsonb_array_length(mfa.recovery_code_hashes), 0)
                        AS mfa_recovery_codes_remaining,
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM user_system_roles system_role
                        WHERE system_role.user_id = users.id
                            AND system_role.role_code = 'SUPERADMIN'
                    ) THEN 1 ELSE 0 END AS is_superadmin
                FROM user_sessions sessions
                INNER JOIN users ON users.id = sessions.user_id
                LEFT JOIN user_mfa_credentials mfa ON mfa.user_id = users.id
                WHERE sessions.token_hash = :token_hash
                    AND sessions.revoked_at IS NULL
                    AND sessions.expires_at > CURRENT_TIMESTAMP
                    AND users.status = 'ACTIVE'
                SQL,
            ['token_hash' => $tokenHash],
        );

        if ($row === false) {
            return null;
        }

        $sessionId = $this->stringValue($row, 'session_id');
        $actorUserId = $this->stringValue($row, 'user_id');
        $actorEmail = $this->stringValue($row, 'email');
        $actorDisplayName = $this->stringValue($row, 'display_name');
        $actorPreferredLocale = $this->stringValue(
            $row,
            'preferred_locale',
        );
        $actorHasSuperadminRole = $this->boolValue($row, 'is_superadmin');
        $mfaEnabled = $this->nullableStringValue(
            $row,
            'mfa_enabled_at',
        ) !== null;
        $mfaVerified = $mfaEnabled && $this->nullableStringValue(
            $row,
            'mfa_verified_at',
        ) !== null;
        $actorIsSuperadmin = $actorHasSuperadminRole && (
            $mfaEnabled
                ? $mfaVerified
                : !$this->superadminMfaRequired
        );
        $mfaEnrollmentRequired = $actorHasSuperadminRole
            && $this->superadminMfaRequired
            && !$mfaEnabled;
        $impersonation = $this->impersonations->findOpenForSession($sessionId);
        $effectiveUserId = $actorUserId;
        $effectiveEmail = $actorEmail;
        $effectiveDisplayName = $actorDisplayName;
        $effectivePreferredLocale = $actorPreferredLocale;

        if ($impersonation !== null) {
            $effectiveUserId = $impersonation->effectiveUserId;
            $effectiveEmail = $impersonation->effectiveUserEmail;
            $effectiveDisplayName = $impersonation->effectiveUserDisplayName;
            $effectivePreferredLocale = $impersonation
                ->effectiveUserPreferredLocale;
        }

        return new SessionContext(
            sessionId: $sessionId,
            actorUserId: $actorUserId,
            actorEmail: $actorEmail,
            actorDisplayName: $actorDisplayName,
            actorIsSuperadmin: $actorIsSuperadmin,
            actorHasSuperadminRole: $actorHasSuperadminRole,
            userId: $effectiveUserId,
            email: $effectiveEmail,
            displayName: $effectiveDisplayName,
            preferredLocale: $effectivePreferredLocale,
            csrfTokenHash: $this->stringValue($row, 'csrf_token_hash'),
            isSuperadmin: $impersonation === null && $actorIsSuperadmin,
            mfaEnabled: $mfaEnabled,
            mfaVerified: $mfaVerified,
            mfaEnrollmentRequired: $mfaEnrollmentRequired,
            mfaRecoveryCodesRemaining: $this->intValue(
                $row,
                'mfa_recovery_codes_remaining',
            ),
            impersonation: $impersonation,
        );
    }

    public function listActiveForUser(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, ip_address, user_agent, created_at, last_seen_at, expires_at
                FROM user_sessions
                WHERE user_id = :user_id
                    AND revoked_at IS NULL
                    AND expires_at > CURRENT_TIMESTAMP
                ORDER BY created_at DESC, id DESC
                SQL,
            ['user_id' => $userId],
        );
        $sessions = [];

        foreach ($rows as $row) {
            $sessions[] = new SessionSummary(
                id: $this->stringValue($row, 'id'),
                ipAddress: $this->nullableStringValue($row, 'ip_address'),
                userAgent: $this->nullableStringValue($row, 'user_agent'),
                createdAt: new DateTimeImmutable($this->stringValue($row, 'created_at')),
                lastSeenAt: new DateTimeImmutable($this->stringValue($row, 'last_seen_at')),
                expiresAt: new DateTimeImmutable($this->stringValue($row, 'expires_at')),
            );
        }

        return $sessions;
    }

    public function touch(string $sessionId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_sessions
                SET last_seen_at = CURRENT_TIMESTAMP
                WHERE id = :session_id
                    AND last_seen_at < CURRENT_TIMESTAMP - INTERVAL '1 minute'
                SQL,
            ['session_id' => $sessionId],
        );
    }

    public function revoke(
        string $sessionId,
        string $userId,
        string $reason,
    ): bool {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_sessions
                SET revoked_at = CURRENT_TIMESTAMP,
                    revocation_reason = :reason
                WHERE id = :session_id
                    AND user_id = :user_id
                    AND revoked_at IS NULL
                SQL,
            [
                'reason' => $reason,
                'session_id' => $sessionId,
                'user_id' => $userId,
            ],
        );

        return $affectedRows === 1;
    }

    public function revokeAllForUser(string $userId, string $reason): int
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_sessions
                SET revoked_at = CURRENT_TIMESTAMP,
                    revocation_reason = :reason
                WHERE user_id = :user_id
                    AND revoked_at IS NULL
                SQL,
            [
                'reason' => $reason,
                'user_id' => $userId,
            ],
        );

        if (is_int($affectedRows)) {
            return $affectedRows;
        }

        if (ctype_digit($affectedRows)) {
            return intval($affectedRows);
        }

        throw new RuntimeException(
            'The database returned an invalid affected-row count.',
        );
    }

    public function markMfaVerified(
        string $sessionId,
        string $userId,
        DateTimeImmutable $verifiedAt,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_sessions
                SET mfa_verified_at = :verified_at
                WHERE id = :session_id
                    AND user_id = :user_id
                    AND revoked_at IS NULL
                    AND expires_at > :verified_at
                SQL,
            [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'verified_at' => $verifiedAt->format('Y-m-d H:i:s.uP'),
            ],
        ) === 1;
    }

    public function clearMfaVerificationForUser(string $userId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_sessions
                SET mfa_verified_at = NULL
                WHERE user_id = :user_id
                    AND mfa_verified_at IS NOT NULL
                SQL,
            ['user_id' => $userId],
        );
    }

    public function revokeOtherForUser(
        string $userId,
        string $currentSessionId,
        string $reason,
    ): int {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_sessions
                SET revoked_at = CURRENT_TIMESTAMP,
                    revocation_reason = :reason
                WHERE user_id = :user_id
                    AND id <> :current_session_id
                    AND revoked_at IS NULL
                SQL,
            [
                'user_id' => $userId,
                'current_session_id' => $currentSessionId,
                'reason' => $reason,
            ],
        );

        return $this->affectedRows($affectedRows);
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

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a nullable string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function boolValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 't' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'f' || $value === 'false') {
            return false;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain a boolean.',
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return intval($value);
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer.',
            $key,
        ));
    }

    private function affectedRows(int|string $affectedRows): int
    {
        if (is_int($affectedRows)) {
            return $affectedRows;
        }

        if (ctype_digit($affectedRows)) {
            return intval($affectedRows);
        }

        throw new RuntimeException(
            'The database returned an invalid affected-row count.',
        );
    }
}
