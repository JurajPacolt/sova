<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Identity\Application\Impersonation\ImpersonationDetails;
use Sova\Identity\Application\Impersonation\ImpersonationRepository;
use Sova\Identity\Application\Impersonation\ImpersonationStatus;
use Sova\Identity\Application\Impersonation\ImpersonationTarget;

final readonly class DoctrineImpersonationRepository implements ImpersonationRepository
{
    public function __construct(private Connection $connection) {}

    public function lockActiveSession(
        string $sessionId,
        string $actorUserId,
    ): bool {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM user_sessions
                WHERE id = :session_id
                    AND user_id = :actor_user_id
                    AND revoked_at IS NULL
                    AND expires_at > CURRENT_TIMESTAMP
                FOR UPDATE
                SQL,
            [
                'session_id' => $sessionId,
                'actor_user_id' => $actorUserId,
            ],
        ) !== false;
    }

    public function closeExpiredForSession(
        string $sessionId,
        DateTimeImmutable $endedAt,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE impersonations
                SET ended_at = :ended_at,
                    end_reason = 'EXPIRED'
                WHERE session_id = :session_id
                    AND ended_at IS NULL
                    AND expires_at <= :ended_at
                SQL,
            [
                'session_id' => $sessionId,
                'ended_at' => $this->date($endedAt),
            ],
        );
    }

    public function hasOpenForSession(string $sessionId): bool
    {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM impersonations
                WHERE session_id = :session_id
                    AND ended_at IS NULL
                SQL,
            ['session_id' => $sessionId],
        ) !== false;
    }

    public function findEligibleTarget(
        string $tenantId,
        string $effectiveUserId,
    ): ?ImpersonationTarget {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    users.id AS user_id,
                    users.email,
                    users.display_name,
                    users.preferred_locale,
                    tenants.id AS tenant_id,
                    tenants.name AS tenant_name,
                    tenants.slug AS tenant_slug
                FROM tenant_memberships membership
                INNER JOIN users ON users.id = membership.user_id
                INNER JOIN tenants ON tenants.id = membership.tenant_id
                WHERE membership.tenant_id = :tenant_id
                    AND membership.user_id = :effective_user_id
                    AND membership.status = 'ACTIVE'
                    AND users.status = 'ACTIVE'
                    AND tenants.status = 'ACTIVE'
                FOR SHARE OF membership, users, tenants
                SQL,
            [
                'tenant_id' => $tenantId,
                'effective_user_id' => $effectiveUserId,
            ],
        );

        if ($row === false) {
            return null;
        }

        return new ImpersonationTarget(
            userId: $this->string($row, 'user_id'),
            email: $this->string($row, 'email'),
            displayName: $this->string($row, 'display_name'),
            preferredLocale: $this->string($row, 'preferred_locale'),
            tenantId: $this->string($row, 'tenant_id'),
            tenantName: $this->string($row, 'tenant_name'),
            tenantSlug: $this->string($row, 'tenant_slug'),
        );
    }

    public function create(
        string $id,
        string $sessionId,
        string $actorUserId,
        string $effectiveUserId,
        string $tenantId,
        string $reason,
        DateTimeImmutable $reauthenticatedAt,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $expiresAt,
    ): ImpersonationDetails {
        $this->connection->insert('impersonations', [
            'id' => $id,
            'session_id' => $sessionId,
            'actor_user_id' => $actorUserId,
            'effective_user_id' => $effectiveUserId,
            'tenant_id' => $tenantId,
            'reason' => $reason,
            'reauthenticated_at' => $this->date($reauthenticatedAt),
            'started_at' => $this->date($startedAt),
            'expires_at' => $this->date($expiresAt),
        ]);
        $details = $this->findOpenForSession($sessionId);

        if ($details === null) {
            throw new RuntimeException(
                'The created impersonation could not be loaded.',
            );
        }

        return $details;
    }

    public function end(
        string $id,
        string $sessionId,
        DateTimeImmutable $endedAt,
        string $endReason,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE impersonations
                SET ended_at = :ended_at,
                    end_reason = :end_reason
                WHERE id = :id
                    AND session_id = :session_id
                    AND ended_at IS NULL
                SQL,
            [
                'id' => $id,
                'session_id' => $sessionId,
                'ended_at' => $this->date($endedAt),
                'end_reason' => $endReason,
            ],
        ) === 1;
    }

    public function findOpenForSession(
        string $sessionId,
    ): ?ImpersonationDetails {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    impersonation.id,
                    impersonation.session_id,
                    impersonation.actor_user_id,
                    actor.email AS actor_email,
                    actor.display_name AS actor_display_name,
                    impersonation.effective_user_id,
                    effective.email AS effective_user_email,
                    effective.display_name AS effective_user_display_name,
                    effective.preferred_locale AS effective_user_preferred_locale,
                    impersonation.tenant_id,
                    tenant.name AS tenant_name,
                    tenant.slug AS tenant_slug,
                    impersonation.reason,
                    impersonation.reauthenticated_at,
                    impersonation.started_at,
                    impersonation.expires_at,
                    CASE
                        WHEN impersonation.expires_at <= CURRENT_TIMESTAMP
                            THEN 'EXPIRED'
                        WHEN actor.status <> 'ACTIVE'
                            OR effective.status <> 'ACTIVE'
                            OR tenant.status <> 'ACTIVE'
                            OR membership.id IS NULL
                            OR membership.status <> 'ACTIVE'
                            OR system_role.user_id IS NULL
                            THEN 'INVALIDATED'
                        ELSE 'ACTIVE'
                    END AS status
                FROM impersonations impersonation
                INNER JOIN users actor
                    ON actor.id = impersonation.actor_user_id
                INNER JOIN users effective
                    ON effective.id = impersonation.effective_user_id
                INNER JOIN tenants tenant
                    ON tenant.id = impersonation.tenant_id
                LEFT JOIN tenant_memberships membership
                    ON membership.tenant_id = impersonation.tenant_id
                    AND membership.user_id = impersonation.effective_user_id
                LEFT JOIN user_system_roles system_role
                    ON system_role.user_id = impersonation.actor_user_id
                    AND system_role.role_code = 'SUPERADMIN'
                WHERE impersonation.session_id = :session_id
                    AND impersonation.ended_at IS NULL
                SQL,
            ['session_id' => $sessionId],
        );

        return $row === false ? null : $this->details($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function details(array $row): ImpersonationDetails
    {
        return new ImpersonationDetails(
            id: $this->string($row, 'id'),
            sessionId: $this->string($row, 'session_id'),
            actorUserId: $this->string($row, 'actor_user_id'),
            actorEmail: $this->string($row, 'actor_email'),
            actorDisplayName: $this->string($row, 'actor_display_name'),
            effectiveUserId: $this->string($row, 'effective_user_id'),
            effectiveUserEmail: $this->string($row, 'effective_user_email'),
            effectiveUserDisplayName: $this->string(
                $row,
                'effective_user_display_name',
            ),
            effectiveUserPreferredLocale: $this->string(
                $row,
                'effective_user_preferred_locale',
            ),
            tenantId: $this->string($row, 'tenant_id'),
            tenantName: $this->string($row, 'tenant_name'),
            tenantSlug: $this->string($row, 'tenant_slug'),
            reason: $this->string($row, 'reason'),
            reauthenticatedAt: $this->dateTime(
                $row,
                'reauthenticated_at',
            ),
            startedAt: $this->dateTime($row, 'started_at'),
            expiresAt: $this->dateTime($row, 'expires_at'),
            status: ImpersonationStatus::from($this->string($row, 'status')),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Impersonation column "%s" is malformed.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dateTime(array $row, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string($row, $key));
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }
}
