<?php

declare(strict_types=1);

namespace Sova\Projects\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Projects\Application\ProjectDetails;
use Sova\Projects\Application\ProjectRepository;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Projects\Domain\ProjectVisibility;
use ValueError;

final readonly class DoctrineProjectRepository implements ProjectRepository
{
    public function __construct(private Connection $connection) {}

    public function listForTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->detailsSql() . "\nWHERE project.tenant_id = :tenant_id"
                . "\nORDER BY LOWER(project.code), project.id",
            ['tenant_id' => $tenantId],
        );

        return array_map($this->hydrate(...), $rows);
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
            FROM projects project
            LEFT JOIN tenant_memberships lead_membership
                ON lead_membership.tenant_id = project.tenant_id
                AND lead_membership.id = project.lead_membership_id
            LEFT JOIN users lead_user
                ON lead_user.id = lead_membership.user_id
            SQL;
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
