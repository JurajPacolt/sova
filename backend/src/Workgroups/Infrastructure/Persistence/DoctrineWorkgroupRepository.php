<?php

declare(strict_types=1);

namespace Sova\Workgroups\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Workgroups\Application\WorkgroupDetails;
use Sova\Workgroups\Application\WorkgroupMemberDetails;
use Sova\Workgroups\Application\WorkgroupRepository;
use Sova\Workgroups\Domain\WorkgroupMemberRole;
use Sova\Workgroups\Domain\WorkgroupStatus;
use ValueError;

final readonly class DoctrineWorkgroupRepository implements WorkgroupRepository
{
    public function __construct(private Connection $connection) {}

    public function listForTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->detailsSql() . "\nWHERE workgroup.tenant_id = :tenant_id"
                . "\nORDER BY LOWER(workgroup.name), workgroup.id",
            ['tenant_id' => $tenantId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findForTenant(
        string $tenantId,
        string $workgroupId,
        bool $forUpdate = false,
    ): ?WorkgroupDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
            . "\nWHERE workgroup.tenant_id = :tenant_id AND workgroup.id = :workgroup_id"
            . ($forUpdate ? "\nFOR UPDATE OF workgroup" : ''),
            ['tenant_id' => $tenantId, 'workgroup_id' => $workgroupId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(
        string $workgroupId,
        string $tenantId,
        string $name,
        string $description,
        string $createdByUserId,
    ): void {
        $this->connection->insert('workgroups', [
            'id' => $workgroupId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => $description,
            'status' => WorkgroupStatus::Active->value,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function changeStatus(
        string $tenantId,
        string $workgroupId,
        WorkgroupStatus $status,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE workgroups
                SET status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :workgroup_id
                SQL,
            [
                'status' => $status->value,
                'tenant_id' => $tenantId,
                'workgroup_id' => $workgroupId,
            ],
        );
    }

    public function listMembers(string $tenantId, string $workgroupId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    member.membership_id,
                    membership.user_id,
                    user_account.email,
                    user_account.display_name,
                    member.member_role,
                    member.joined_at
                FROM workgroup_members member
                INNER JOIN tenant_memberships membership
                    ON membership.tenant_id = member.tenant_id
                    AND membership.id = member.membership_id
                INNER JOIN users user_account
                    ON user_account.id = membership.user_id
                WHERE member.tenant_id = :tenant_id
                    AND member.workgroup_id = :workgroup_id
                ORDER BY LOWER(user_account.display_name), member.membership_id
                SQL,
            ['tenant_id' => $tenantId, 'workgroup_id' => $workgroupId],
        );

        return array_map($this->hydrateMember(...), $rows);
    }

    public function membershipStatus(
        string $tenantId,
        string $membershipId,
    ): ?string {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT status
                FROM tenant_memberships
                WHERE tenant_id = :tenant_id
                    AND id = :membership_id
                SQL,
            ['tenant_id' => $tenantId, 'membership_id' => $membershipId],
        );

        return is_string($value) ? $value : null;
    }

    public function memberRole(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
        bool $forUpdate = false,
    ): ?WorkgroupMemberRole {
        $value = $this->connection->fetchOne(
            <<<SQL
                SELECT member_role
                FROM workgroup_members
                WHERE tenant_id = :tenant_id
                    AND workgroup_id = :workgroup_id
                    AND membership_id = :membership_id
                {$this->lockClause($forUpdate)}
                SQL,
            [
                'tenant_id' => $tenantId,
                'workgroup_id' => $workgroupId,
                'membership_id' => $membershipId,
            ],
        );

        if ($value === false) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(
                'Expected the workgroup member role to be a string.',
            );
        }

        return WorkgroupMemberRole::from($value);
    }

    public function upsertMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
        WorkgroupMemberRole $role,
        string $addedByUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO workgroup_members (
                    tenant_id,
                    workgroup_id,
                    membership_id,
                    member_role,
                    added_by_user_id
                )
                VALUES (
                    :tenant_id,
                    :workgroup_id,
                    :membership_id,
                    :member_role,
                    :added_by_user_id
                )
                ON CONFLICT (workgroup_id, membership_id) DO UPDATE
                SET member_role = EXCLUDED.member_role
                SQL,
            [
                'tenant_id' => $tenantId,
                'workgroup_id' => $workgroupId,
                'membership_id' => $membershipId,
                'member_role' => $role->value,
                'added_by_user_id' => $addedByUserId,
            ],
        );
    }

    public function removeMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
    ): void {
        $this->connection->delete('workgroup_members', [
            'tenant_id' => $tenantId,
            'workgroup_id' => $workgroupId,
            'membership_id' => $membershipId,
        ]);
    }

    private function lockClause(bool $forUpdate): string
    {
        return $forUpdate ? 'FOR UPDATE' : '';
    }

    private function detailsSql(): string
    {
        return <<<'SQL'
            SELECT
                workgroup.id,
                workgroup.tenant_id,
                workgroup.name,
                workgroup.description,
                workgroup.status,
                workgroup.created_at,
                workgroup.updated_at,
                (
                    SELECT COUNT(*)
                    FROM workgroup_members member
                    WHERE member.tenant_id = workgroup.tenant_id
                        AND member.workgroup_id = workgroup.id
                ) AS member_count
            FROM workgroups workgroup
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WorkgroupDetails
    {
        $statusValue = $this->stringValue($row, 'status');

        try {
            $status = WorkgroupStatus::from($statusValue);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf('Unknown workgroup status "%s".', $statusValue),
                previous: $exception,
            );
        }

        return new WorkgroupDetails(
            id: $this->stringValue($row, 'id'),
            tenantId: $this->stringValue($row, 'tenant_id'),
            name: $this->stringValue($row, 'name'),
            description: $this->stringValue($row, 'description'),
            status: $status,
            memberCount: $this->integerValue($row, 'member_count'),
            createdAt: new DateTimeImmutable(
                $this->stringValue($row, 'created_at'),
            ),
            updatedAt: new DateTimeImmutable(
                $this->stringValue($row, 'updated_at'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateMember(array $row): WorkgroupMemberDetails
    {
        $roleValue = $this->stringValue($row, 'member_role');

        try {
            $role = WorkgroupMemberRole::from($roleValue);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf('Unknown workgroup member role "%s".', $roleValue),
                previous: $exception,
            );
        }

        return new WorkgroupMemberDetails(
            membershipId: $this->stringValue($row, 'membership_id'),
            userId: $this->stringValue($row, 'user_id'),
            email: $this->stringValue($row, 'email'),
            displayName: $this->stringValue($row, 'display_name'),
            role: $role,
            joinedAt: new DateTimeImmutable(
                $this->stringValue($row, 'joined_at'),
            ),
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
    private function integerValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer.',
            $key,
        ));
    }
}
