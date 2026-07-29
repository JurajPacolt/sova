<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;
use Sova\ProjectConfiguration\Application\IssueMigrator;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * The issues module's adapter for the configuration module's {@see IssueMigrator}
 * port: only this module writes the issue tables. It runs inside the caller's
 * publish transaction, so the migration commits atomically with the workflow
 * version switch (WORKFLOW-A-TYPY-ULOH.md §8.2).
 */
final readonly class DoctrineIssueMigrator implements IssueMigrator
{
    private const AGGREGATE_TYPE = 'ISSUE';

    public function __construct(private Connection $connection) {}

    public function countIssuesByStatus(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT status_id, COUNT(*) AS issue_count
                FROM issues
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_version_id = :workflow_version_id
                GROUP BY status_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $workflowVersionId,
            ],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[$this->stringValue($row, 'status_id')] = $this->intValue($row, 'issue_count');
        }

        return $counts;
    }

    /**
     * @param array<string, string> $statusIdMapping
     *
     * @throws JsonException
     */
    public function migrateWorkflowVersion(
        string $tenantId,
        string $projectId,
        string $fromVersionId,
        string $toVersionId,
        array $statusIdMapping,
        string $actorUserId,
    ): int {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, status_id, version
                FROM issues
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_version_id = :from_version_id
                ORDER BY number
                FOR UPDATE
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'from_version_id' => $fromVersionId,
            ],
        );

        $migrated = 0;

        foreach ($rows as $row) {
            $issueId = $this->stringValue($row, 'id');
            $fromStatusId = $this->stringValue($row, 'status_id');
            $currentVersion = $this->intValue($row, 'version');
            $toStatusId = $statusIdMapping[$fromStatusId] ?? $fromStatusId;
            $newVersion = $currentVersion + 1;

            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE issues
                    SET workflow_version_id = :to_version_id,
                        status_id = :to_status_id,
                        version = version + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE tenant_id = :tenant_id
                        AND id = :issue_id
                        AND version = :expected_version
                    SQL,
                [
                    'to_version_id' => $toVersionId,
                    'to_status_id' => $toStatusId,
                    'tenant_id' => $tenantId,
                    'issue_id' => $issueId,
                    'expected_version' => $currentVersion,
                ],
            );

            $this->connection->insert('issue_history', [
                'id' => (string) UuidV7::generate(),
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_id' => $issueId,
                'issue_version' => $newVersion,
                'event_type' => 'ISSUE_MIGRATED',
                'actor_user_id' => $actorUserId,
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatusId,
                'metadata' => json_encode([
                    'from_workflow_version_id' => $fromVersionId,
                    'to_workflow_version_id' => $toVersionId,
                ], JSON_THROW_ON_ERROR),
            ]);

            $this->connection->insert('outbox_events', [
                'id' => (string) UuidV7::generate(),
                'tenant_id' => $tenantId,
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $issueId,
                'event_name' => 'ISSUE_MIGRATED',
                'event_version' => 1,
                'sequence_number' => $newVersion,
                'payload' => json_encode([
                    'project_id' => $projectId,
                    'from_workflow_version_id' => $fromVersionId,
                    'to_workflow_version_id' => $toVersionId,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $toStatusId,
                ], JSON_THROW_ON_ERROR),
            ]);

            ++$migrated;
        }

        return $migrated;
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
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer.',
            $key,
        ));
    }
}
