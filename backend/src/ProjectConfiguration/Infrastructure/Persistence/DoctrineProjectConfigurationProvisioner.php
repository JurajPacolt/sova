<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use RuntimeException;
use Sova\ProjectConfiguration\Application\ProjectConfigurationProvisioner;
use Sova\ProjectConfiguration\Domain\DefaultTemplate;
use Sova\ProjectConfiguration\Domain\WorkflowVersionState;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineProjectConfigurationProvisioner implements
    ProjectConfigurationProvisioner
{
    public function __construct(private Connection $connection) {}

    public function provisionDefaults(
        string $tenantId,
        string $projectId,
        ?string $createdByUserId = null,
    ): void {
        $statusIds = $this->insertStatuses($tenantId, $projectId);
        $typeIds = $this->insertIssueTypes($tenantId, $projectId);
        $workflowId = $this->insertWorkflow($tenantId, $projectId);
        $versionId = $this->insertPublishedVersion(
            $tenantId,
            $projectId,
            $workflowId,
            $statusIds,
        );
        $this->insertTransitions($tenantId, $projectId, $versionId, $statusIds);
        $this->mapTypesToWorkflow($tenantId, $projectId, $typeIds, $workflowId);
        $this->insertConfigurationRevision($tenantId, $projectId);
    }

    private function insertConfigurationRevision(string $tenantId, string $projectId): void
    {
        // The revision seeds the publishing optimistic lock and cache key. The
        // insert is idempotent so re-provisioning a project never fails here.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_configuration_revisions (tenant_id, project_id)
                VALUES (:tenant_id, :project_id)
                ON CONFLICT (project_id) DO NOTHING
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );
    }

    /**
     * @return array<string, string> status code to identifier
     */
    private function insertStatuses(string $tenantId, string $projectId): array
    {
        $ids = [];

        foreach (DefaultTemplate::statuses() as $status) {
            $id = (string) UuidV7::generate();
            $this->connection->insert('project_statuses', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'code' => $status['code'],
                'name' => $status['name'],
                'category' => $status['category']->value,
                'position' => $status['position'],
            ]);
            $ids[$status['code']] = $id;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function insertIssueTypes(string $tenantId, string $projectId): array
    {
        $ids = [];

        foreach (DefaultTemplate::issueTypes() as $type) {
            $id = (string) UuidV7::generate();
            $this->connection->insert('project_issue_types', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'code' => $type['code'],
                'name' => $type['name'],
                'hierarchy_level' => $type['level']->value,
                'position' => $type['position'],
            ]);
            $ids[] = $id;
        }

        return $ids;
    }

    private function insertWorkflow(string $tenantId, string $projectId): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('project_workflows', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'name' => DefaultTemplate::WORKFLOW_NAME,
        ]);

        return $id;
    }

    /**
     * @param array<string, string> $statusIds
     */
    private function insertPublishedVersion(
        string $tenantId,
        string $projectId,
        string $workflowId,
        array $statusIds,
    ): string {
        $versionId = (string) UuidV7::generate();
        // The published state and its timestamp must land in the same row: the
        // table CHECK rejects a published version without `published_at`.
        $this->connection->insert('project_workflow_versions', [
            'id' => $versionId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'workflow_id' => $workflowId,
            'version_number' => 1,
            'state' => WorkflowVersionState::Published->value,
            'initial_status_id' => $this->statusId(
                $statusIds,
                DefaultTemplate::INITIAL_STATUS_CODE,
            ),
            'published_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ], ['published_at' => Types::DATETIMETZ_IMMUTABLE]);

        foreach (DefaultTemplate::statuses() as $status) {
            $this->connection->insert('workflow_version_statuses', [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $versionId,
                'status_id' => $this->statusId($statusIds, $status['code']),
                'position' => $status['position'],
            ]);
        }

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_workflows
                SET active_version_id = :version_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :workflow_id
                SQL,
            [
                'version_id' => $versionId,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );

        return $versionId;
    }

    /**
     * @param array<string, string> $statusIds
     */
    private function insertTransitions(
        string $tenantId,
        string $projectId,
        string $versionId,
        array $statusIds,
    ): void {
        foreach (DefaultTemplate::transitions() as $transition) {
            $this->connection->insert('project_workflow_transitions', [
                'id' => (string) UuidV7::generate(),
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $versionId,
                'code' => $transition['code'],
                'name' => $transition['name'],
                'from_status_id' => $this->statusId($statusIds, $transition['from']),
                'to_status_id' => $this->statusId($statusIds, $transition['to']),
                'is_primary' => $transition['primary'],
                'position' => $transition['position'],
            ], ['is_primary' => 'boolean']);
        }
    }

    /**
     * @param list<string> $typeIds
     */
    private function mapTypesToWorkflow(
        string $tenantId,
        string $projectId,
        array $typeIds,
        string $workflowId,
    ): void {
        foreach ($typeIds as $typeId) {
            $this->connection->insert('project_issue_type_workflows', [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $typeId,
                'workflow_id' => $workflowId,
            ]);
        }
    }

    /**
     * @param array<string, string> $statusIds
     */
    private function statusId(array $statusIds, string $code): string
    {
        $id = $statusIds[$code] ?? null;

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'The default template references unknown status "%s".',
                $code,
            ));
        }

        return $id;
    }
}
