<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use RuntimeException;
use Sova\Issues\Application\IssueDetails;
use Sova\Issues\Application\IssueRecord;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\TransitionEffect;
use Sova\Issues\Domain\IssuePriority;
use Sova\ProjectConfiguration\Domain\StatusCategory;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineIssueRepository implements IssueRepository
{
    public function __construct(private Connection $connection) {}

    public function listForProject(
        string $tenantId,
        string $projectId,
        int $limit,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            $this->detailsSql()
                . "\nWHERE issue.tenant_id = :tenant_id AND issue.project_id = :project_id"
                . "\nORDER BY issue.number DESC"
                . "\nLIMIT :limit",
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'limit' => $limit,
            ],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $tenantId, string $issueId): ?IssueDetails
    {
        return $this->fetchOne($tenantId, $issueId, false);
    }

    public function findForUpdate(string $tenantId, string $issueId): ?IssueDetails
    {
        return $this->fetchOne($tenantId, $issueId, true);
    }

    public function reserveNumber(string $tenantId, string $projectId): int
    {
        // One statement, so two concurrent creations can never take the same
        // number: the insert seeds the counter, the conflict path increments it.
        $value = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO project_issue_counters (tenant_id, project_id, next_number)
                VALUES (:tenant_id, :project_id, 2)
                ON CONFLICT (project_id) DO UPDATE
                SET next_number = project_issue_counters.next_number + 1
                RETURNING next_number - 1
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException('The project issue counter did not return a number.');
    }

    public function create(IssueRecord $record): void
    {
        $this->connection->insert('issues', [
            'id' => $record->id,
            'tenant_id' => $record->tenantId,
            'project_id' => $record->projectId,
            'number' => $record->number,
            'issue_key' => $record->key,
            'title' => $record->title,
            'description' => $record->description,
            'issue_type_id' => $record->issueTypeId,
            'workflow_version_id' => $record->workflowVersionId,
            'status_id' => $record->statusId,
            'parent_issue_id' => $record->parentIssueId,
            'reporter_membership_id' => $record->reporterMembershipId,
            'assignee_membership_id' => $record->assigneeMembershipId,
            'assignee_workgroup_id' => $record->assigneeWorkgroupId,
            'priority' => $record->priority->value,
            'created_by_user_id' => $record->createdByUserId,
        ]);
    }

    public function applyTransition(
        string $tenantId,
        string $issueId,
        string $statusId,
        int $expectedVersion,
        TransitionEffect $effect,
    ): bool {
        $assignments = [
            'status_id = :status_id',
            'version = version + 1',
            'updated_at = CURRENT_TIMESTAMP',
        ];
        $parameters = [
            'status_id' => $statusId,
            'tenant_id' => $tenantId,
            'issue_id' => $issueId,
            'expected_version' => $expectedVersion,
        ];

        if ($effect->touchesResolution) {
            $assignments[] = 'resolution = :resolution';
            $parameters['resolution'] = $effect->resolution;
        }

        if ($effect->touchesResolvedAt) {
            $assignments[] = 'resolved_at = ' .
                ($effect->resolvedAtToNow ? 'CURRENT_TIMESTAMP' : 'NULL');
        }

        $affected = $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE issues
                    SET %s
                    WHERE tenant_id = :tenant_id
                        AND id = :issue_id
                        AND version = :expected_version
                    SQL,
                implode(",\n    ", $assignments),
            ),
            $parameters,
        );

        return $affected === 1;
    }

    public function applyTypeChange(
        string $tenantId,
        string $issueId,
        string $issueTypeId,
        string $workflowVersionId,
        string $statusId,
        int $expectedVersion,
    ): bool {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE issues
                SET issue_type_id = :issue_type_id,
                    workflow_version_id = :workflow_version_id,
                    status_id = :status_id,
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :issue_id
                    AND version = :expected_version
                SQL,
            [
                'issue_type_id' => $issueTypeId,
                'workflow_version_id' => $workflowVersionId,
                'status_id' => $statusId,
                'tenant_id' => $tenantId,
                'issue_id' => $issueId,
                'expected_version' => $expectedVersion,
            ],
        );

        return $affected === 1;
    }

    /**
     * @throws JsonException
     */
    public function recordHistory(
        string $tenantId,
        string $projectId,
        string $issueId,
        int $issueVersion,
        string $eventType,
        ?string $actorUserId,
        ?string $transitionId,
        ?string $fromStatusId,
        ?string $toStatusId,
        array $metadata = [],
        bool $changesIssue = true,
    ): void {
        $row = [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'issue_id' => $issueId,
            'issue_version' => $issueVersion,
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'transition_id' => $transitionId,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'changes_issue' => $changesIssue,
        ];

        // Leave the column at its '{}' default unless there is something to say.
        if ($metadata !== []) {
            $row['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);
        }

        // Without an explicit type DBAL sends a PHP boolean as an empty string,
        // which PostgreSQL refuses for a boolean column.
        $this->connection->insert(
            'issue_history',
            $row,
            ['changes_issue' => ParameterType::BOOLEAN],
        );
    }

    public function parentHierarchyLevel(
        string $tenantId,
        string $projectId,
        string $parentIssueId,
    ): ?int {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT issue_type.hierarchy_level
                FROM issues issue
                INNER JOIN project_issue_types issue_type
                    ON issue_type.tenant_id = issue.tenant_id
                    AND issue_type.project_id = issue.project_id
                    AND issue_type.id = issue.issue_type_id
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                    AND issue.id = :issue_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_id' => $parentIssueId,
            ],
        );

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1
            ? (int) $value
            : null;
    }

    public function childHierarchyLevels(
        string $tenantId,
        string $projectId,
        string $parentIssueId,
    ): array {
        $values = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT issue_type.hierarchy_level
                FROM issues issue
                INNER JOIN project_issue_types issue_type
                    ON issue_type.tenant_id = issue.tenant_id
                    AND issue_type.project_id = issue.project_id
                    AND issue_type.id = issue.issue_type_id
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                    AND issue.parent_issue_id = :parent_issue_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'parent_issue_id' => $parentIssueId,
            ],
        );

        $levels = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $levels[] = $value;

                continue;
            }

            if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                $levels[] = (int) $value;
            }
        }

        return $levels;
    }

    public function membershipIsActive(string $tenantId, string $membershipId): bool
    {
        return $this->exists(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM tenant_memberships
                    WHERE tenant_id = :tenant_id
                        AND id = :id
                        AND status = 'ACTIVE'
                )
                SQL,
            ['tenant_id' => $tenantId, 'id' => $membershipId],
        );
    }

    public function workgroupIsActive(string $tenantId, string $workgroupId): bool
    {
        return $this->exists(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM workgroups
                    WHERE tenant_id = :tenant_id
                        AND id = :id
                        AND status = 'ACTIVE'
                )
                SQL,
            ['tenant_id' => $tenantId, 'id' => $workgroupId],
        );
    }

    private function fetchOne(
        string $tenantId,
        string $issueId,
        bool $forUpdate,
    ): ?IssueDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
                . "\nWHERE issue.tenant_id = :tenant_id AND issue.id = :issue_id"
                . ($forUpdate ? "\nFOR UPDATE OF issue" : ''),
            ['tenant_id' => $tenantId, 'issue_id' => $issueId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function exists(string $sql, array $parameters): bool
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function detailsSql(): string
    {
        return <<<'SQL'
            SELECT
                issue.id,
                issue.tenant_id,
                issue.project_id,
                issue.number,
                issue.issue_key,
                issue.title,
                issue.description,
                issue.issue_type_id,
                issue_type.code AS issue_type_code,
                issue_type.name AS issue_type_name,
                issue.workflow_version_id,
                issue.status_id,
                status.code AS status_code,
                status.name AS status_name,
                status.category AS status_category,
                issue.parent_issue_id,
                parent.issue_key AS parent_issue_key,
                issue.reporter_membership_id,
                reporter_user.display_name AS reporter_display_name,
                issue.assignee_membership_id,
                assignee_user.display_name AS assignee_display_name,
                issue.assignee_workgroup_id,
                assignee_workgroup.name AS assignee_workgroup_name,
                issue.priority,
                issue.resolution,
                issue.resolved_at,
                issue.version,
                issue.created_at,
                issue.updated_at
            FROM issues issue
            INNER JOIN project_issue_types issue_type
                ON issue_type.tenant_id = issue.tenant_id
                AND issue_type.project_id = issue.project_id
                AND issue_type.id = issue.issue_type_id
            INNER JOIN project_statuses status
                ON status.tenant_id = issue.tenant_id
                AND status.project_id = issue.project_id
                AND status.id = issue.status_id
            LEFT JOIN issues parent
                ON parent.tenant_id = issue.tenant_id
                AND parent.project_id = issue.project_id
                AND parent.id = issue.parent_issue_id
            INNER JOIN tenant_memberships reporter
                ON reporter.tenant_id = issue.tenant_id
                AND reporter.id = issue.reporter_membership_id
            INNER JOIN users reporter_user
                ON reporter_user.id = reporter.user_id
            LEFT JOIN tenant_memberships assignee
                ON assignee.tenant_id = issue.tenant_id
                AND assignee.id = issue.assignee_membership_id
            LEFT JOIN users assignee_user
                ON assignee_user.id = assignee.user_id
            LEFT JOIN workgroups assignee_workgroup
                ON assignee_workgroup.tenant_id = issue.tenant_id
                AND assignee_workgroup.id = issue.assignee_workgroup_id
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): IssueDetails
    {
        $priority = IssuePriority::tryFrom($this->stringValue($row, 'priority'));
        $category = StatusCategory::tryFrom($this->stringValue($row, 'status_category'));

        if ($priority === null || $category === null) {
            throw new RuntimeException('The issue row carries an unknown enum value.');
        }

        return new IssueDetails(
            id: $this->stringValue($row, 'id'),
            tenantId: $this->stringValue($row, 'tenant_id'),
            projectId: $this->stringValue($row, 'project_id'),
            number: $this->integerValue($row, 'number'),
            key: $this->stringValue($row, 'issue_key'),
            title: $this->stringValue($row, 'title'),
            description: $this->stringValue($row, 'description'),
            issueTypeId: $this->stringValue($row, 'issue_type_id'),
            issueTypeCode: $this->stringValue($row, 'issue_type_code'),
            issueTypeName: $this->stringValue($row, 'issue_type_name'),
            workflowVersionId: $this->stringValue($row, 'workflow_version_id'),
            statusId: $this->stringValue($row, 'status_id'),
            statusCode: $this->stringValue($row, 'status_code'),
            statusName: $this->stringValue($row, 'status_name'),
            statusCategory: $category,
            parentIssueId: $this->nullableStringValue($row, 'parent_issue_id'),
            parentIssueKey: $this->nullableStringValue($row, 'parent_issue_key'),
            reporterMembershipId: $this->stringValue($row, 'reporter_membership_id'),
            reporterDisplayName: $this->nullableStringValue($row, 'reporter_display_name'),
            assigneeMembershipId: $this->nullableStringValue($row, 'assignee_membership_id'),
            assigneeDisplayName: $this->nullableStringValue($row, 'assignee_display_name'),
            assigneeWorkgroupId: $this->nullableStringValue($row, 'assignee_workgroup_id'),
            assigneeWorkgroupName: $this->nullableStringValue($row, 'assignee_workgroup_name'),
            priority: $priority,
            resolution: $this->nullableStringValue($row, 'resolution'),
            resolvedAt: $this->nullableDateTime($row, 'resolved_at'),
            version: $this->integerValue($row, 'version'),
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
    private function nullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a nullable timestamp.',
                $key,
            ));
        }

        return new DateTimeImmutable($value);
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
