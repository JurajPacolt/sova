<?php

declare(strict_types=1);

namespace Sova\Projects\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Projects\Application\ProjectDetails;
use Sova\Projects\Application\ProjectListItem;
use Sova\Projects\Application\ProjectRepository;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Projects\Domain\ProjectVisibility;
use ValueError;

final readonly class DoctrineProjectRepository implements ProjectRepository
{
    public function __construct(private Connection $connection) {}

    public function listForTenant(string $tenantId, string $viewerUserId): array
    {
        return $this->fetchListing($tenantId, $viewerUserId, null);
    }

    public function listVisibleForUser(string $tenantId, string $userId): array
    {
        return $this->fetchListing(
            $tenantId,
            $userId,
            "(listing.visibility = 'TENANT' AND listing.viewer_is_member)"
                . "\n    OR listing.viewer_roles <> ''",
        );
    }

    /**
     * @return list<ProjectListItem>
     */
    private function fetchListing(
        string $tenantId,
        string $viewerUserId,
        ?string $visibilityCondition,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT *\nFROM (\n%s\n) AS listing\n%sORDER BY LOWER(listing.code), listing.id",
                $this->listingSql(),
                $visibilityCondition === null
                    ? ''
                    : sprintf("WHERE %s\n", $visibilityCondition),
            ),
            ['tenant_id' => $tenantId, 'viewer_user_id' => $viewerUserId],
        );

        return array_map($this->hydrateListItem(...), $rows);
    }

    public function findForTenant(
        string $tenantId,
        string $projectId,
        bool $forUpdate = false,
    ): ?ProjectDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
            . "\nWHERE project.tenant_id = :tenant_id AND project.id = :project_id"
            . ($forUpdate ? "\nFOR UPDATE OF project" : ''),
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(
        string $projectId,
        string $tenantId,
        string $code,
        string $name,
        string $description,
        ProjectVisibility $visibility,
        ?string $leadMembershipId,
        string $createdByUserId,
    ): void {
        $this->connection->insert('projects', [
            'id' => $projectId,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'visibility' => $visibility->value,
            'status' => ProjectStatus::Active->value,
            'lead_membership_id' => $leadMembershipId,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function changeStatus(
        string $tenantId,
        string $projectId,
        ProjectStatus $status,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE projects
                SET status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :project_id
                SQL,
            [
                'status' => $status->value,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
            ],
        );
    }

    public function changeVisibility(
        string $tenantId,
        string $projectId,
        ProjectVisibility $visibility,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE projects
                SET visibility = :visibility,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :project_id
                SQL,
            [
                'visibility' => $visibility->value,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
            ],
        );
    }

    public function hasActiveManager(
        string $tenantId,
        string $projectId,
    ): bool {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM project_roles role
                    INNER JOIN project_role_permissions permission
                        ON permission.tenant_id = role.tenant_id
                        AND permission.project_id = role.project_id
                        AND permission.role_id = role.id
                        AND permission.permission_code = 'project.settings.manage'
                    WHERE role.tenant_id = :tenant_id
                        AND role.project_id = :project_id
                        AND role.status = 'ACTIVE'
                        AND (
                            EXISTS (
                                SELECT 1
                                FROM project_membership_role_assignments assignment
                                INNER JOIN tenant_memberships membership
                                    ON membership.tenant_id = assignment.tenant_id
                                    AND membership.id = assignment.membership_id
                                WHERE assignment.tenant_id = role.tenant_id
                                    AND assignment.project_id = role.project_id
                                    AND assignment.role_id = role.id
                                    AND membership.status = 'ACTIVE'
                            )
                            OR EXISTS (
                                SELECT 1
                                FROM project_workgroups linked
                                INNER JOIN workgroups workgroup
                                    ON workgroup.tenant_id = linked.tenant_id
                                    AND workgroup.id = linked.workgroup_id
                                    AND workgroup.status = 'ACTIVE'
                                INNER JOIN workgroup_members workgroup_member
                                    ON workgroup_member.tenant_id = linked.tenant_id
                                    AND workgroup_member.workgroup_id = linked.workgroup_id
                                INNER JOIN tenant_memberships membership
                                    ON membership.tenant_id = workgroup_member.tenant_id
                                    AND membership.id = workgroup_member.membership_id
                                    AND membership.status = 'ACTIVE'
                                WHERE linked.tenant_id = role.tenant_id
                                    AND linked.project_id = role.project_id
                                    AND linked.role_id = role.id
                            )
                        )
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
            ],
        ) === true;
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

    private function detailsSql(): string
    {
        return $this->columnsSql() . "\n" . $this->fromSql();
    }

    /**
     * Adds the requesting user's own project roles and tenant membership so a
     * listing can be scoped to what that user is allowed to see.
     */
    private function listingSql(): string
    {
        return $this->columnsSql()
            . ",\n" . $this->viewerColumnsSql()
            . "\n" . $this->fromSql()
            . "\nWHERE project.tenant_id = :tenant_id";
    }

    private function columnsSql(): string
    {
        return <<<'SQL'
            SELECT
                project.id,
                project.tenant_id,
                project.code,
                project.name,
                project.description,
                project.visibility,
                project.status,
                project.lead_membership_id,
                lead_user.display_name AS lead_display_name,
                lead_user.email AS lead_email,
                project.created_at,
                project.updated_at,
                (
                    SELECT COUNT(DISTINCT assignment.membership_id)
                    FROM project_membership_role_assignments assignment
                    WHERE assignment.tenant_id = project.tenant_id
                        AND assignment.project_id = project.id
                ) AS member_count
            SQL;
    }

    private function fromSql(): string
    {
        return <<<'SQL'
            FROM projects project
            LEFT JOIN tenant_memberships lead_membership
                ON lead_membership.tenant_id = project.tenant_id
                AND lead_membership.id = project.lead_membership_id
            LEFT JOIN users lead_user
                ON lead_user.id = lead_membership.user_id
            SQL;
    }

    private function viewerColumnsSql(): string
    {
        return <<<'SQL'
                COALESCE((
                    SELECT string_agg(DISTINCT role.code, ',' ORDER BY role.code)
                    FROM project_roles role
                    WHERE role.tenant_id = project.tenant_id
                        AND role.project_id = project.id
                        AND role.status = 'ACTIVE'
                        AND (
                            EXISTS (
                                SELECT 1
                                FROM project_membership_role_assignments assignment
                                INNER JOIN tenant_memberships assigned_membership
                                    ON assigned_membership.tenant_id = assignment.tenant_id
                                    AND assigned_membership.id = assignment.membership_id
                                WHERE assignment.tenant_id = role.tenant_id
                                    AND assignment.project_id = role.project_id
                                    AND assignment.role_id = role.id
                                    AND assigned_membership.user_id = :viewer_user_id
                                    AND assigned_membership.status = 'ACTIVE'
                            )
                            OR EXISTS (
                                SELECT 1
                                FROM project_workgroups linked
                                INNER JOIN workgroups workgroup
                                    ON workgroup.tenant_id = linked.tenant_id
                                    AND workgroup.id = linked.workgroup_id
                                INNER JOIN workgroup_members workgroup_member
                                    ON workgroup_member.tenant_id = linked.tenant_id
                                    AND workgroup_member.workgroup_id = linked.workgroup_id
                                INNER JOIN tenant_memberships linked_membership
                                    ON linked_membership.tenant_id = workgroup_member.tenant_id
                                    AND linked_membership.id = workgroup_member.membership_id
                                WHERE linked.tenant_id = role.tenant_id
                                    AND linked.project_id = role.project_id
                                    AND linked.role_id = role.id
                                    AND linked_membership.user_id = :viewer_user_id
                                    AND linked_membership.status = 'ACTIVE'
                                    AND workgroup.status = 'ACTIVE'
                            )
                        )
                ), '') AS viewer_roles,
                EXISTS (
                    SELECT 1
                    FROM tenant_memberships viewer_membership
                    WHERE viewer_membership.tenant_id = project.tenant_id
                        AND viewer_membership.user_id = :viewer_user_id
                        AND viewer_membership.status = 'ACTIVE'
                ) AS viewer_is_member
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateListItem(array $row): ProjectListItem
    {
        return new ProjectListItem($this->hydrate($row), $this->roleCodes($row));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function roleCodes(array $row): array
    {
        $value = $row['viewer_roles'] ?? '';

        if (!is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', $value),
            static fn(string $code): bool => $code !== '',
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProjectDetails
    {
        $statusValue = $this->stringValue($row, 'status');
        $visibilityValue = $this->stringValue($row, 'visibility');

        try {
            $status = ProjectStatus::from($statusValue);
            $visibility = ProjectVisibility::from($visibilityValue);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf(
                    'Unknown project status "%s" or visibility "%s".',
                    $statusValue,
                    $visibilityValue,
                ),
                previous: $exception,
            );
        }

        return new ProjectDetails(
            id: $this->stringValue($row, 'id'),
            tenantId: $this->stringValue($row, 'tenant_id'),
            code: $this->stringValue($row, 'code'),
            name: $this->stringValue($row, 'name'),
            description: $this->stringValue($row, 'description'),
            visibility: $visibility,
            status: $status,
            leadMembershipId: $this->nullableStringValue($row, 'lead_membership_id'),
            leadDisplayName: $this->nullableStringValue($row, 'lead_display_name'),
            leadEmail: $this->nullableStringValue($row, 'lead_email'),
            memberCount: $this->integerValue($row, 'member_count'),
            createdAt: new DateTimeImmutable($this->stringValue($row, 'created_at')),
            updatedAt: new DateTimeImmutable($this->stringValue($row, 'updated_at')),
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
