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

final readonly class DoctrineUserSessionRepository implements UserSessionRepository
{
    public function __construct(
        private Connection $connection,
        private ImpersonationRepository $impersonations,
    ) {}

    public function create(
        string $sessionId,
        string $userId,
        string $tokenHash,
        string $csrfTokenHash,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->connection->insert('user_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'csrf_token_hash' => $csrfTokenHash,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
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
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM user_system_roles system_role
                        WHERE system_role.user_id = users.id
                            AND system_role.role_code = 'SUPERADMIN'
                    ) THEN 1 ELSE 0 END AS is_superadmin
                FROM user_sessions sessions
                INNER JOIN users ON users.id = sessions.user_id
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
        $actorIsSuperadmin = $this->boolValue($row, 'is_superadmin');
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
            userId: $effectiveUserId,
            email: $effectiveEmail,
            displayName: $effectiveDisplayName,
            preferredLocale: $effectivePreferredLocale,
            csrfTokenHash: $this->stringValue($row, 'csrf_token_hash'),
            isSuperadmin: $impersonation === null && $actorIsSuperadmin,
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
}
