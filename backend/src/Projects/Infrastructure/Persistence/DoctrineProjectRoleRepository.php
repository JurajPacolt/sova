<?php

declare(strict_types=1);

namespace Sova\Projects\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Projects\Application\ProjectMemberDetails;
use Sova\Projects\Application\ProjectMemberRoleDetails;
use Sova\Projects\Application\ProjectRoleDetails;
use Sova\Projects\Application\ProjectRoleRepository;
use Sova\Projects\Application\ProjectWorkgroupLinkDetails;

final readonly class DoctrineProjectRoleRepository implements ProjectRoleRepository
{
    public function __construct(private Connection $connection) {}

    public function listForProject(string $tenantId, string $projectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->detailsSql()
                . "\nWHERE role.tenant_id = :tenant_id AND role.project_id = :project_id"
                . "\nORDER BY LOWER(role.name), role.id",
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findForProject(
        string $tenantId,
        string $projectId,
        string $roleId,
        bool $forUpdate = false,
    ): ?ProjectRoleDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
            . "\nWHERE role.tenant_id = :tenant_id AND role.project_id = :project_id"
                . " AND role.id = :role_id"
            . ($forUpdate ? "\nFOR UPDATE OF role" : ''),
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'role_id' => $roleId,
            ],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(
        string $tenantId,
        string $projectId,
        string $code,
    ): ?ProjectRoleDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
                . "\nWHERE role.tenant_id = :tenant_id AND role.project_id = :project_id"
                . " AND role.code = :code",
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'code' => $code,
            ],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function listMembers(string $tenantId, string $projectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    membership.id AS membership_id,
                    user_account.id AS user_id,
                    user_account.email,
                    user_account.display_name,
                    role.id AS role_id,
                    role.code AS role_code,
                    role.name AS role_name
                FROM project_membership_role_assignments assignment
                INNER JOIN tenant_memberships membership
                    ON membership.tenant_id = assignment.tenant_id
                    AND membership.id = assignment.membership_id
                INNER JOIN users user_account
                    ON user_account.id = membership.user_id
                INNER JOIN project_roles role
                    ON role.tenant_id = assignment.tenant_id
                    AND role.project_id = assignment.project_id
                    AND role.id = assignment.role_id
                WHERE assignment.tenant_id = :tenant_id
                    AND assignment.project_id = :project_id
                ORDER BY LOWER(user_account.display_name), membership.id, role.name
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        /**
         * @var array<string, array{
         *     membership_id: string,
         *     user_id: string,
         *     email: string,
         *     display_name: string,
         *     roles: list<ProjectMemberRoleDetails>,
         * }> $grouped
         */
        $grouped = [];

        foreach ($rows as $row) {
            $membershipId = $this->stringValue($row, 'membership_id');

            if (!isset($grouped[$membershipId])) {
                $grouped[$membershipId] = [
                    'membership_id' => $membershipId,
                    'user_id' => $this->stringValue($row, 'user_id'),
                    'email' => $this->stringValue($row, 'email'),
                    'display_name' => $this->stringValue($row, 'display_name'),
                    'roles' => [],
                ];
            }

            $grouped[$membershipId]['roles'][] = new ProjectMemberRoleDetails(
                $this->stringValue($row, 'role_id'),
                $this->stringValue($row, 'role_code'),
                $this->stringValue($row, 'role_name'),
            );
        }

        return array_values(array_map(
            static fn(array $entry): ProjectMemberDetails => new ProjectMemberDetails(
                $entry['membership_id'],
                $entry['user_id'],
                $entry['email'],
                $entry['display_name'],
                $entry['roles'],
            ),
            $grouped,
        ));
    }

    public function assignmentExists(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM project_membership_role_assignments
                    WHERE tenant_id = :tenant_id
                        AND project_id = :project_id
                        AND membership_id = :membership_id
                        AND role_id = :role_id
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'membership_id' => $membershipId,
                'role_id' => $roleId,
            ],
        );

        return $this->boolValue($value);
    }

    public function assign(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_membership_role_assignments (
                    tenant_id,
                    project_id,
                    membership_id,
                    role_id,
                    granted_by_user_id
                )
                VALUES (
                    :tenant_id,
                    :project_id,
                    :membership_id,
                    :role_id,
                    :granted_by_user_id
                )
                ON CONFLICT (project_id, membership_id, role_id) DO NOTHING
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'membership_id' => $membershipId,
                'role_id' => $roleId,
                'granted_by_user_id' => $actorUserId,
            ],
        );
    }

    public function unassign(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
    ): void {
        $this->connection->delete('project_membership_role_assignments', [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);
    }

    public function listWorkgroupLinks(string $tenantId, string $projectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    linked.workgroup_id,
                    workgroup.name AS workgroup_name,
                    linked.role_id,
                    role.code AS role_code,
                    role.name AS role_name
                FROM project_workgroups linked
                INNER JOIN workgroups workgroup
                    ON workgroup.tenant_id = linked.tenant_id
                    AND workgroup.id = linked.workgroup_id
                INNER JOIN project_roles role
                    ON role.tenant_id = linked.tenant_id
                    AND role.project_id = linked.project_id
                    AND role.id = linked.role_id
                WHERE linked.tenant_id = :tenant_id
                    AND linked.project_id = :project_id
                ORDER BY LOWER(workgroup.name)
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return array_map(
            fn(array $row): ProjectWorkgroupLinkDetails => new ProjectWorkgroupLinkDetails(
                $this->stringValue($row, 'workgroup_id'),
                $this->stringValue($row, 'workgroup_name'),
                $this->stringValue($row, 'role_id'),
                $this->stringValue($row, 'role_code'),
                $this->stringValue($row, 'role_name'),
            ),
            $rows,
        );
    }

    public function workgroupLinkExists(
        string $tenantId,
        string $projectId,
        string $workgroupId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM project_workgroups
                    WHERE tenant_id = :tenant_id
                        AND project_id = :project_id
                        AND workgroup_id = :workgroup_id
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workgroup_id' => $workgroupId,
            ],
        );

        return $this->boolValue($value);
    }

    public function workgroupStatus(string $tenantId, string $workgroupId): ?string
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT status
                FROM workgroups
                WHERE tenant_id = :tenant_id
                    AND id = :workgroup_id
                SQL,
            ['tenant_id' => $tenantId, 'workgroup_id' => $workgroupId],
        );

        return is_string($value) ? $value : null;
    }

    public function linkWorkgroup(
        string $tenantId,
        string $projectId,
        string $workgroupId,
        string $roleId,
        string $actorUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_workgroups (
                    tenant_id,
                    project_id,
                    workgroup_id,
                    role_id,
                    added_by_user_id
                )
                VALUES (
                    :tenant_id,
                    :project_id,
                    :workgroup_id,
                    :role_id,
                    :added_by_user_id
                )
                ON CONFLICT (project_id, workgroup_id) DO UPDATE
                SET role_id = EXCLUDED.role_id,
                    added_by_user_id = EXCLUDED.added_by_user_id,
                    added_at = CURRENT_TIMESTAMP
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workgroup_id' => $workgroupId,
                'role_id' => $roleId,
                'added_by_user_id' => $actorUserId,
            ],
        );
    }

    public function unlinkWorkgroup(
        string $tenantId,
        string $projectId,
        string $workgroupId,
    ): void {
        $this->connection->delete('project_workgroups', [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'workgroup_id' => $workgroupId,
        ]);
    }

    private function detailsSql(): string
    {
        return <<<'SQL'
            SELECT
                role.id,
                role.project_id,
                role.code,
                role.name,
                role.description,
                role.status,
                role.is_system,
                role.is_editable,
                role.revision,
                (
                    SELECT COALESCE(ARRAY_AGG(permission.permission_code ORDER BY permission.permission_code), '{}')
                    FROM project_role_permissions permission
                    WHERE permission.tenant_id = role.tenant_id
                        AND permission.project_id = role.project_id
                        AND permission.role_id = role.id
                ) AS permission_codes,
                (
                    SELECT COUNT(DISTINCT assignment.membership_id)
                    FROM project_membership_role_assignments assignment
                    WHERE assignment.tenant_id = role.tenant_id
                        AND assignment.project_id = role.project_id
                        AND assignment.role_id = role.id
                ) AS assignment_count
            FROM project_roles role
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProjectRoleDetails
    {
        $permissionCodes = $row['permission_codes'] ?? null;

        if (!is_array($permissionCodes)) {
            throw new RuntimeException(
                'Expected project role permission codes to be an array.',
            );
        }

        /** @var list<string> $codes */
        $codes = array_values(array_map(
            static function (mixed $code): string {
                if (!is_string($code)) {
                    throw new RuntimeException(
                        'Expected each project role permission code to be a string.',
                    );
                }

                return $code;
            },
            $permissionCodes,
        ));

        return new ProjectRoleDetails(
            id: $this->stringValue($row, 'id'),
            projectId: $this->stringValue($row, 'project_id'),
            code: $this->stringValue($row, 'code'),
            name: $this->stringValue($row, 'name'),
            description: $this->stringValue($row, 'description'),
            status: $this->stringValue($row, 'status'),
            isSystem: $this->boolValue($row['is_system'] ?? null),
            isEditable: $this->boolValue($row['is_editable'] ?? null),
            revision: $this->integerValue($row, 'revision'),
            permissionCodes: $codes,
            assignmentCount: $this->integerValue($row, 'assignment_count'),
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

    private function boolValue(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't';
    }
}
