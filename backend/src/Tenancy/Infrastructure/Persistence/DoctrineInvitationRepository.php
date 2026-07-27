<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Tenancy\Application\Invitation\InvitationRepository;
use Sova\Tenancy\Application\Invitation\TenantInvitation;
use Sova\Tenancy\Domain\Membership\MembershipStatus;

final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    public function __construct(private Connection $connection) {}

    public function lockTenant(string $tenantId): bool
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenants
                WHERE id = :tenant_id
                    AND status IN ('PENDING', 'ACTIVE', 'SUSPENDED')
                FOR UPDATE
                SQL,
            ['tenant_id' => $tenantId],
        );

        return is_string($id);
    }

    public function expirePendingForEmail(
        string $tenantId,
        string $normalizedEmail,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenant_invitations
                SET status = 'EXPIRED',
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND normalized_email = :normalized_email
                    AND status = 'PENDING'
                    AND expires_at <= CURRENT_TIMESTAMP
                SQL,
            [
                'tenant_id' => $tenantId,
                'normalized_email' => $normalizedEmail,
            ],
        );
    }

    public function pendingExists(
        string $tenantId,
        string $normalizedEmail,
    ): bool {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM tenant_invitations
                    WHERE tenant_id = :tenant_id
                        AND normalized_email = :normalized_email
                        AND status = 'PENDING'
                        AND expires_at > CURRENT_TIMESTAMP
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'normalized_email' => $normalizedEmail,
            ],
        ) === true;
    }

    public function activeMembershipExistsForEmail(
        string $tenantId,
        string $normalizedEmail,
    ): bool {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM tenant_memberships membership
                    INNER JOIN users ON users.id = membership.user_id
                    WHERE membership.tenant_id = :tenant_id
                        AND users.normalized_email = :normalized_email
                        AND membership.status = 'ACTIVE'
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'normalized_email' => $normalizedEmail,
            ],
        ) === true;
    }

    public function create(
        string $invitationId,
        string $tenantId,
        string $email,
        string $normalizedEmail,
        string $invitedByUserId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        ?string $initialRoleCode = null,
    ): void {
        $this->connection->insert('tenant_invitations', [
            'id' => $invitationId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'normalized_email' => $normalizedEmail,
            'invited_by_user_id' => $invitedByUserId,
            'token_hash' => $tokenHash,
            'status' => 'PENDING',
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            'initial_role_code' => $initialRoleCode,
        ]);
    }

    public function findUsableByTokenHash(
        string $tokenHash,
        bool $forUpdate = false,
    ): ?TenantInvitation {
        $lockingClause = $forUpdate
            ? 'FOR UPDATE OF invitation, tenant'
            : '';
        $row = $this->connection->fetchAssociative(
            sprintf(
                <<<'SQL'
                    SELECT
                        invitation.id,
                        invitation.tenant_id,
                        tenant.name AS tenant_name,
                        tenant.slug AS tenant_slug,
                        invitation.email,
                        invitation.normalized_email,
                        invitation.invited_by_user_id,
                        inviter.display_name AS invited_by_display_name,
                        invitation.initial_role_code,
                        invitation.expires_at
                    FROM tenant_invitations invitation
                    INNER JOIN tenants tenant ON tenant.id = invitation.tenant_id
                    INNER JOIN users inviter
                        ON inviter.id = invitation.invited_by_user_id
                    WHERE invitation.token_hash = :token_hash
                        AND invitation.status = 'PENDING'
                        AND invitation.expires_at > CURRENT_TIMESTAMP
                        AND tenant.status IN ('PENDING', 'ACTIVE', 'SUSPENDED')
                    %s
                    SQL,
                $lockingClause,
            ),
            ['token_hash' => $tokenHash],
        );

        if ($row === false) {
            return null;
        }

        return new TenantInvitation(
            id: $this->stringValue($row, 'id'),
            tenantId: $this->stringValue($row, 'tenant_id'),
            tenantName: $this->stringValue($row, 'tenant_name'),
            tenantSlug: $this->stringValue($row, 'tenant_slug'),
            email: $this->stringValue($row, 'email'),
            normalizedEmail: $this->stringValue($row, 'normalized_email'),
            invitedByUserId: $this->stringValue($row, 'invited_by_user_id'),
            invitedByDisplayName: $this->stringValue(
                $row,
                'invited_by_display_name',
            ),
            initialRoleCode: $this->nullableStringValue(
                $row,
                'initial_role_code',
            ),
            expiresAt: new DateTimeImmutable(
                $this->stringValue($row, 'expires_at'),
            ),
        );
    }

    public function membershipStatus(
        string $tenantId,
        string $userId,
    ): ?MembershipStatus {
        $status = $this->connection->fetchOne(
            <<<'SQL'
                SELECT status
                FROM tenant_memberships
                WHERE tenant_id = :tenant_id
                    AND user_id = :user_id
                FOR UPDATE
                SQL,
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ],
        );

        return is_string($status) ? MembershipStatus::from($status) : null;
    }

    public function createMembership(
        string $membershipId,
        string $tenantId,
        string $userId,
    ): void {
        $this->connection->insert('tenant_memberships', [
            'id' => $membershipId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => MembershipStatus::Active->value,
        ]);
    }

    public function membershipId(
        string $tenantId,
        string $userId,
    ): ?string {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenant_memberships
                WHERE tenant_id = :tenant_id
                    AND user_id = :user_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ],
        );

        return is_string($value) ? $value : null;
    }

    public function assignInitialRole(
        string $tenantId,
        string $membershipId,
        string $roleCode,
        string $assignedByUserId,
    ): bool {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_membership_role_assignments (
                    tenant_id,
                    membership_id,
                    role_id,
                    granted_by_user_id
                )
                SELECT
                    :tenant_id,
                    :membership_id,
                    role.id,
                    :granted_by_user_id
                FROM tenant_roles role
                WHERE role.tenant_id = :tenant_id
                    AND role.code = :role_code
                    AND role.status = 'ACTIVE'
                ON CONFLICT (tenant_id, membership_id, role_id) DO NOTHING
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'role_code' => $roleCode,
                'granted_by_user_id' => $assignedByUserId,
            ],
        );

        if ($affected === 1) {
            return true;
        }

        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM tenant_membership_role_assignments assignment
                    INNER JOIN tenant_roles role
                        ON role.tenant_id = assignment.tenant_id
                        AND role.id = assignment.role_id
                    WHERE assignment.tenant_id = :tenant_id
                        AND assignment.membership_id = :membership_id
                        AND role.code = :role_code
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'role_code' => $roleCode,
            ],
        ) === true;
    }

    public function activatePendingTenant(string $tenantId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenants
                SET status = 'ACTIVE',
                    revision = revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :tenant_id
                    AND status = 'PENDING'
                SQL,
            ['tenant_id' => $tenantId],
        );
    }

    public function createVerifiedUser(
        string $userId,
        string $email,
        string $normalizedEmail,
        string $passwordHash,
        string $displayName,
        string $preferredLocale,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (
                    id,
                    email,
                    normalized_email,
                    password_hash,
                    display_name,
                    preferred_locale,
                    status,
                    email_verified_at
                )
                VALUES (
                    :id,
                    :email,
                    :normalized_email,
                    :password_hash,
                    :display_name,
                    :preferred_locale,
                    'ACTIVE',
                    CURRENT_TIMESTAMP
                )
                SQL,
            [
                'id' => $userId,
                'email' => $email,
                'normalized_email' => $normalizedEmail,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'preferred_locale' => $preferredLocale,
            ],
        );
    }

    public function accept(string $invitationId, string $userId): bool
    {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenant_invitations
                SET status = 'ACCEPTED',
                    accepted_by_user_id = :user_id,
                    accepted_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :invitation_id
                    AND status = 'PENDING'
                    AND expires_at > CURRENT_TIMESTAMP
                SQL,
            [
                'invitation_id' => $invitationId,
                'user_id' => $userId,
            ],
        ) === 1;
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
}
