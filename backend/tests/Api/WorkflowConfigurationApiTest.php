<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class WorkflowConfigurationApiTest extends TestCase
{
    private const PASSWORD = 'A unique workflow configuration passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $memberId;
    private string $foreignOwnerId;
    private string $tenantId;
    private string $foreignTenantId;
    private string $projectId;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 2));
        $connection = $app->getContainer()->get(Connection::class);
        $roles = $app->getContainer()->get(TenantRoleProvisioner::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->ownerId = $this->insertUser('workflow-owner');
        $this->memberId = $this->insertUser('workflow-member');
        $this->foreignOwnerId = $this->insertUser('workflow-foreign-owner');
        $this->tenantId = $this->insertTenant('workflow-primary');
        $this->foreignTenantId = $this->insertTenant('workflow-foreign');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $roles->provisionDefaults($this->foreignTenantId, $this->foreignOwnerId);
        $ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $this->ownerId,
            DefaultRole::TenantOwner,
        );
        $this->addMembership($this->tenantId, $this->memberId, DefaultRole::Member);
        $this->addMembership(
            $this->foreignTenantId,
            $this->foreignOwnerId,
            DefaultRole::TenantOwner,
        );
        $this->projectId = $this->createProject($ownerMembershipId);
    }

    protected function tearDown(): void
    {
        if (
            isset($this->connection)
            && $this->connection->isTransactionActive()
        ) {
            $this->connection->rollBack();
        }
    }

    public function testPublishingADraftBumpsTheRevisionAndRecordsHistoryAndOutbox(): void
    {
        $login = $this->login('workflow-owner');
        $workflowId = $this->workflowId();

        self::assertSame(1, $this->configurationRevision($login));

        $create = $this->createDraft($login, $workflowId);
        self::assertSame(201, $create->getStatusCode());
        self::assertSame(
            'DRAFT',
            $this->stringAt($this->decode($create), ['draft_version', 'state']),
        );

        $body = $this->draftBody(1, [
            ...$this->defaultStatuses(),
            ['code' => 'BLOCKED', 'name' => 'Blocked', 'category' => 'IN_PROGRESS', 'position' => 25],
        ], [
            ...$this->defaultTransitions(),
            ['code' => 'BLOCK', 'name' => 'Block', 'from' => 'OPEN', 'to' => 'BLOCKED', 'position' => 70],
            ['code' => 'UNBLOCK', 'name' => 'Unblock', 'from' => 'BLOCKED', 'to' => 'OPEN', 'position' => 80],
        ]);
        $update = $this->updateDraft($login, $workflowId, $body);
        self::assertSame(200, $update->getStatusCode());

        $validate = $this->validateDraft($login, $workflowId);
        self::assertSame(200, $validate->getStatusCode());
        self::assertTrue($this->decode($validate)['valid'] ?? null);

        $impact = $this->impactReport($login, $workflowId);
        self::assertFalse($impact['requires_migration'] ?? null);
        self::assertTrue($impact['publishable'] ?? null);
        $added = $impact['added_status_codes'] ?? null;
        self::assertIsArray($added);
        self::assertContains('BLOCKED', $added);

        $publish = $this->publish($login, $workflowId, ['expected_config_version' => 1]);
        self::assertSame(200, $publish->getStatusCode());
        $published = $this->decode($publish);
        self::assertSame('PUBLISHED', $this->stringAt($published, ['published_version', 'state']));
        self::assertSame(2, $this->integerAt($published, ['published_version', 'version_number']));

        self::assertSame(2, $this->configurationRevision($login));

        $history = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT event_type
                FROM project_configuration_history
                WHERE project_id = :project_id
                SQL,
            ['project_id' => $this->projectId],
        );
        self::assertSame(['WORKFLOW_PUBLISHED'], $history);

        $outbox = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT event_name
                FROM outbox_events
                WHERE aggregate_type = 'PROJECT_CONFIGURATION'
                    AND aggregate_id = :project_id
                SQL,
            ['project_id' => $this->projectId],
        );
        self::assertSame(['PROJECT_WORKFLOW_PUBLISHED'], $outbox);
    }

    public function testPublishingMigratesIssuesOffARemovedStatus(): void
    {
        $login = $this->login('workflow-owner');
        $workflowId = $this->workflowId();
        $activeVersionId = $this->activeVersionId();
        $issueId = $this->insertIssue('IN_PROGRESS', $activeVersionId);

        $this->createDraft($login, $workflowId);
        $body = $this->draftBody(1, [
            ['code' => 'OPEN', 'name' => 'Open', 'category' => 'TO_DO', 'position' => 10],
            ['code' => 'RESOLVED', 'name' => 'Resolved', 'category' => 'DONE', 'position' => 30],
            ['code' => 'CLOSED', 'name' => 'Closed', 'category' => 'DONE', 'position' => 40],
        ], [
            ['code' => 'RESOLVE_DIRECT', 'name' => 'Resolve', 'from' => 'OPEN', 'to' => 'RESOLVED', 'position' => 10],
            ['code' => 'CLOSE', 'name' => 'Close', 'from' => 'RESOLVED', 'to' => 'CLOSED', 'position' => 20],
            ['code' => 'REOPEN', 'name' => 'Reopen', 'from' => 'RESOLVED', 'to' => 'OPEN', 'position' => 30],
            ['code' => 'REOPEN_CLOSED', 'name' => 'Reopen', 'from' => 'CLOSED', 'to' => 'OPEN', 'position' => 40],
        ]);
        self::assertSame(200, $this->updateDraft($login, $workflowId, $body)->getStatusCode());

        $impact = $this->impactReport($login, $workflowId);
        self::assertTrue($impact['requires_migration'] ?? null);
        self::assertSame(['IN_PROGRESS'], $impact['required_status_mapping_codes'] ?? null);

        $blocked = $this->publish($login, $workflowId, ['expected_config_version' => 1]);
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame(
            'WORKFLOW_MIGRATION_REQUIRED',
            $this->decode($blocked)['code'] ?? null,
        );

        $published = $this->publish($login, $workflowId, [
            'expected_config_version' => 1,
            'status_mapping' => ['IN_PROGRESS' => 'OPEN'],
        ]);
        self::assertSame(200, $published->getStatusCode());
        $newVersionId = $this->stringAt($this->decode($published), ['published_version', 'id']);

        $issue = $this->connection->fetchAssociative(
            'SELECT status_id, workflow_version_id, version FROM issues WHERE id = :id',
            ['id' => $issueId],
        );
        self::assertIsArray($issue);
        self::assertSame($this->statusId('OPEN'), $issue['status_id']);
        self::assertSame($newVersionId, $issue['workflow_version_id']);
        self::assertEquals(2, $issue['version']);

        $history = $this->connection->fetchFirstColumn(
            'SELECT event_type FROM issue_history WHERE issue_id = :id ORDER BY issue_version',
            ['id' => $issueId],
        );
        self::assertSame('ISSUE_MIGRATED', $history[1] ?? null);

        $outbox = $this->connection->fetchFirstColumn(
            "SELECT event_name FROM outbox_events WHERE aggregate_id = :id AND event_name = 'ISSUE_MIGRATED'",
            ['id' => $issueId],
        );
        self::assertSame(['ISSUE_MIGRATED'], $outbox);
    }

    public function testAStaleConfigurationRevisionIsRejected(): void
    {
        $login = $this->login('workflow-owner');
        $workflowId = $this->workflowId();
        $this->createDraft($login, $workflowId);
        $this->updateDraft($login, $workflowId, $this->draftBody(
            1,
            $this->defaultStatuses(),
            $this->defaultTransitions(),
        ));

        $response = $this->publish($login, $workflowId, ['expected_config_version' => 99]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'PROJECT_CONFIG_VERSION_CONFLICT',
            $this->decode($response)['code'] ?? null,
        );
    }

    public function testAnInvalidDraftCannotBePublished(): void
    {
        $login = $this->login('workflow-owner');
        $workflowId = $this->workflowId();
        $this->createDraft($login, $workflowId);

        // ORPHAN is declared but no transition reaches it: the graph is invalid.
        $body = $this->draftBody(1, [
            ...$this->defaultStatuses(),
            ['code' => 'ORPHAN', 'name' => 'Orphan', 'category' => 'TO_DO', 'position' => 90],
        ], $this->defaultTransitions());
        self::assertSame(200, $this->updateDraft($login, $workflowId, $body)->getStatusCode());

        $validate = $this->decode($this->validateDraft($login, $workflowId));
        self::assertFalse($validate['valid'] ?? null);

        $publish = $this->publish($login, $workflowId, ['expected_config_version' => 1]);
        self::assertSame(422, $publish->getStatusCode());
        self::assertSame('WORKFLOW_INVALID', $this->decode($publish)['code'] ?? null);
    }

    public function testAStructurallyMalformedDraftIsRejected(): void
    {
        $login = $this->login('workflow-owner');
        $workflowId = $this->workflowId();
        $this->createDraft($login, $workflowId);

        $body = $this->draftBody(1, $this->defaultStatuses(), [
            ['code' => 'BROKEN', 'name' => 'Broken', 'from' => 'OPEN', 'to' => 'GHOST', 'position' => 10],
        ]);
        $response = $this->updateDraft($login, $workflowId, $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('WORKFLOW_DRAFT_INVALID', $this->decode($response)['code'] ?? null);
    }

    public function testATenantMemberWithoutTheWorkflowPermissionIsRejected(): void
    {
        $login = $this->login('workflow-member');
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows',
                $this->tenantId,
                $this->projectId,
            ),
            $login,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnotherTenantCannotTouchThisProjectsWorkflow(): void
    {
        $foreignLogin = $this->login('workflow-foreign-owner');
        $workflowId = $this->workflowId();

        $response = $this->app->handle($this->authenticatedRequest(
            'POST',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows/%s/draft',
                $this->foreignTenantId,
                $this->projectId,
                $workflowId,
            ),
            $foreignLogin,
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'PROJECT_RESOURCE_NOT_FOUND',
            $this->decode($response)['code'] ?? null,
        );
    }

    public function testIssueTypeAuthoringUsesBothConfigurationAndRecordVersions(): void
    {
        $login = $this->login('workflow-owner');
        $workflowId = $this->workflowId();
        $create = $this->issueTypeRequest(
            $login,
            'POST',
            null,
            [
                'code' => 'INCIDENT',
                'name' => 'Incident',
                'description' => 'Customer-impacting incident.',
                'hierarchy_level' => 0,
                'position' => 60,
                'icon' => 'alert',
                'color_token' => 'danger',
                'workflow_id' => $workflowId,
                'expected_config_version' => 1,
            ],
        );
        self::assertSame(201, $create->getStatusCode());
        $created = $this->decode($create)['issue_type'] ?? null;
        self::assertIsArray($created);
        $issueTypeId = $created['id'] ?? null;
        self::assertIsString($issueTypeId);
        self::assertSame(1, $created['version'] ?? null);
        self::assertSame('alert', $created['icon'] ?? null);
        self::assertSame(2, $this->configurationRevision($login));

        $stale = $this->issueTypeRequest(
            $login,
            'PATCH',
            $issueTypeId,
            [
                'name' => 'Service incident',
                'description' => 'Customer-impacting incident.',
                'hierarchy_level' => 1,
                'position' => 5,
                'icon' => 'alert',
                'color_token' => 'danger',
                'workflow_id' => $workflowId,
                'expected_config_version' => 1,
                'expected_type_version' => 1,
            ],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(
            'PROJECT_CONFIG_VERSION_CONFLICT',
            $this->decode($stale)['code'] ?? null,
        );

        $update = $this->issueTypeRequest(
            $login,
            'PATCH',
            $issueTypeId,
            [
                'name' => 'Service incident',
                'description' => 'Customer-impacting incident.',
                'hierarchy_level' => 1,
                'position' => 5,
                'icon' => 'alert',
                'color_token' => 'danger',
                'workflow_id' => $workflowId,
                'expected_config_version' => 2,
                'expected_type_version' => 1,
            ],
        );
        self::assertSame(200, $update->getStatusCode());
        $updated = $this->decode($update)['issue_type'] ?? null;
        self::assertIsArray($updated);
        self::assertSame('INCIDENT', $updated['code'] ?? null);
        self::assertSame('Service incident', $updated['name'] ?? null);
        self::assertSame(1, $updated['hierarchy_level'] ?? null);
        self::assertSame(2, $updated['version'] ?? null);

        $archive = $this->issueTypeRequest(
            $login,
            'POST',
            $issueTypeId,
            [
                'expected_config_version' => 3,
                'expected_type_version' => 2,
            ],
            true,
        );
        self::assertSame(200, $archive->getStatusCode());
        $archived = $this->decode($archive)['issue_type'] ?? null;
        self::assertIsArray($archived);
        self::assertSame('ARCHIVED', $archived['status'] ?? null);
        self::assertSame(3, $archived['version'] ?? null);
        self::assertSame(4, $this->configurationRevision($login));

        $repeated = $this->issueTypeRequest(
            $login,
            'POST',
            $issueTypeId,
            [
                'expected_config_version' => 4,
                'expected_type_version' => 3,
            ],
            true,
        );
        self::assertSame(200, $repeated->getStatusCode());
        self::assertSame(4, $this->configurationRevision($login));

        self::assertSame(
            ['ISSUE_TYPE_CREATED', 'ISSUE_TYPE_UPDATED', 'ISSUE_TYPE_ARCHIVED'],
            $this->connection->fetchFirstColumn(
                <<<'SQL'
                    SELECT event_type
                    FROM project_configuration_history
                    WHERE project_id = :project_id
                        AND event_type LIKE 'ISSUE_TYPE_%'
                    ORDER BY revision
                    SQL,
                ['project_id' => $this->projectId],
            ),
        );
        self::assertSame(
            ['ISSUE_TYPE_CREATED', 'ISSUE_TYPE_UPDATED', 'ISSUE_TYPE_ARCHIVED'],
            $this->connection->fetchFirstColumn(
                <<<'SQL'
                    SELECT event_name
                    FROM outbox_events
                    WHERE aggregate_id = :project_id
                        AND event_name LIKE 'ISSUE_TYPE_%'
                    ORDER BY sequence_number
                    SQL,
                ['project_id' => $this->projectId],
            ),
        );
    }

    public function testIssueTypeHierarchyChangeCannotInvalidateExistingIssues(): void
    {
        $login = $this->login('workflow-owner');
        $this->insertIssue('OPEN', $this->activeVersionId());
        $task = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, name, description, position, icon, color_token, version
                FROM project_issue_types
                WHERE project_id = :project_id
                    AND code = 'TASK'
                SQL,
            ['project_id' => $this->projectId],
        );
        self::assertIsArray($task);
        $taskId = $task['id'] ?? null;
        self::assertIsString($taskId);

        $response = $this->issueTypeRequest(
            $login,
            'PATCH',
            $taskId,
            [
                'name' => $task['name'],
                'description' => $task['description'],
                'hierarchy_level' => -1,
                'position' => $task['position'],
                'icon' => $task['icon'],
                'color_token' => $task['color_token'],
                'workflow_id' => $this->workflowId(),
                'expected_config_version' => 1,
                'expected_type_version' => $task['version'],
            ],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'ISSUE_TYPE_HIERARCHY_IN_USE',
            $this->decode($response)['code'] ?? null,
        );
        self::assertSame(1, $this->configurationRevision($login));
    }

    public function testMemberCannotAuthorIssueTypes(): void
    {
        $response = $this->issueTypeRequest(
            $this->login('workflow-member'),
            'GET',
        );

        self::assertSame(403, $response->getStatusCode());
    }

    private function createDraft(ResponseInterface $login, string $workflowId): ResponseInterface
    {
        return $this->app->handle($this->authenticatedRequest(
            'POST',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows/%s/draft',
                $this->tenantId,
                $this->projectId,
                $workflowId,
            ),
            $login,
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function issueTypeRequest(
        ResponseInterface $login,
        string $method,
        ?string $issueTypeId = null,
        array $body = [],
        bool $archive = false,
    ): ResponseInterface {
        $path = sprintf(
            '/api/v1/tenants/%s/projects/%s/issue-types',
            $this->tenantId,
            $this->projectId,
        );

        if ($issueTypeId !== null) {
            $path .= sprintf('/%s%s', $issueTypeId, $archive ? '/archive' : '');
        }

        return $this->app->handle(
            $this->authenticatedRequest($method, $path, $login)
                ->withParsedBody($body),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function updateDraft(
        ResponseInterface $login,
        string $workflowId,
        array $body,
    ): ResponseInterface {
        return $this->app->handle($this->authenticatedRequest(
            'PUT',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows/%s/draft',
                $this->tenantId,
                $this->projectId,
                $workflowId,
            ),
            $login,
        )->withParsedBody($body));
    }

    private function validateDraft(ResponseInterface $login, string $workflowId): ResponseInterface
    {
        return $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows/%s/validation',
                $this->tenantId,
                $this->projectId,
                $workflowId,
            ),
            $login,
        ));
    }

    private function impact(ResponseInterface $login, string $workflowId): ResponseInterface
    {
        return $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows/%s/impact',
                $this->tenantId,
                $this->projectId,
                $workflowId,
            ),
            $login,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function impactReport(ResponseInterface $login, string $workflowId): array
    {
        $response = $this->impact($login, $workflowId);
        self::assertSame(200, $response->getStatusCode());
        $impact = $this->decode($response)['impact'] ?? null;
        self::assertIsArray($impact);

        $result = [];

        foreach ($impact as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function publish(
        ResponseInterface $login,
        string $workflowId,
        array $body,
    ): ResponseInterface {
        return $this->app->handle($this->authenticatedRequest(
            'POST',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/workflows/%s/publish',
                $this->tenantId,
                $this->projectId,
                $workflowId,
            ),
            $login,
        )->withParsedBody($body));
    }

    private function configurationRevision(ResponseInterface $login): int
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/configuration',
                $this->tenantId,
                $this->projectId,
            ),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->integerAt($this->decode($response), ['revision']);
    }

    /**
     * @param list<array{code: string, name: string, category: string, position: int}>          $statuses
     * @param list<array{code: string, name: string, from: string, to: string, position: int}>  $transitions
     *
     * @return array<string, mixed>
     */
    private function draftBody(int $expectedVersion, array $statuses, array $transitions): array
    {
        return [
            'expected_version' => $expectedVersion,
            'initial_status_code' => 'OPEN',
            'statuses' => array_map(
                static fn(array $status): array => [
                    'code' => $status['code'],
                    'name' => $status['name'],
                    'category' => $status['category'],
                    'color_token' => '',
                    'position' => $status['position'],
                ],
                $statuses,
            ),
            'transitions' => array_map(
                static fn(array $transition): array => [
                    'code' => $transition['code'],
                    'name' => $transition['name'],
                    'from' => $transition['from'],
                    'to' => $transition['to'],
                    'is_primary' => false,
                    'position' => $transition['position'],
                    'rules' => [],
                ],
                $transitions,
            ),
        ];
    }

    /**
     * @return list<array{code: string, name: string, category: string, position: int}>
     */
    private function defaultStatuses(): array
    {
        return [
            ['code' => 'OPEN', 'name' => 'Open', 'category' => 'TO_DO', 'position' => 10],
            ['code' => 'IN_PROGRESS', 'name' => 'In progress', 'category' => 'IN_PROGRESS', 'position' => 20],
            ['code' => 'RESOLVED', 'name' => 'Resolved', 'category' => 'DONE', 'position' => 30],
            ['code' => 'CLOSED', 'name' => 'Closed', 'category' => 'DONE', 'position' => 40],
        ];
    }

    /**
     * @return list<array{code: string, name: string, from: string, to: string, position: int}>
     */
    private function defaultTransitions(): array
    {
        return [
            ['code' => 'START', 'name' => 'Start', 'from' => 'OPEN', 'to' => 'IN_PROGRESS', 'position' => 10],
            ['code' => 'RESOLVE', 'name' => 'Resolve', 'from' => 'IN_PROGRESS', 'to' => 'RESOLVED', 'position' => 20],
            ['code' => 'CLOSE', 'name' => 'Close', 'from' => 'RESOLVED', 'to' => 'CLOSED', 'position' => 30],
            ['code' => 'REOPEN', 'name' => 'Reopen', 'from' => 'RESOLVED', 'to' => 'OPEN', 'position' => 40],
        ];
    }

    private function workflowId(): string
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM project_workflows WHERE tenant_id = :tenant_id AND project_id = :project_id',
            ['tenant_id' => $this->tenantId, 'project_id' => $this->projectId],
        );
        self::assertIsString($value);

        return $value;
    }

    private function activeVersionId(): string
    {
        $value = $this->connection->fetchOne(
            'SELECT active_version_id FROM project_workflows WHERE tenant_id = :tenant_id AND project_id = :project_id',
            ['tenant_id' => $this->tenantId, 'project_id' => $this->projectId],
        );
        self::assertIsString($value);

        return $value;
    }

    private function statusId(string $code): string
    {
        $value = $this->connection->fetchOne(
            'SELECT id FROM project_statuses WHERE project_id = :project_id AND code = :code',
            ['project_id' => $this->projectId, 'code' => $code],
        );
        self::assertIsString($value);

        return $value;
    }

    private function insertIssue(string $statusCode, string $workflowVersionId): string
    {
        $typeId = $this->connection->fetchOne(
            "SELECT id FROM project_issue_types WHERE project_id = :project_id AND code = 'TASK'",
            ['project_id' => $this->projectId],
        );
        self::assertIsString($typeId);
        $reporterMembershipId = $this->connection->fetchOne(
            'SELECT id FROM tenant_memberships WHERE tenant_id = :tenant_id AND user_id = :user_id',
            ['tenant_id' => $this->tenantId, 'user_id' => $this->ownerId],
        );
        self::assertIsString($reporterMembershipId);

        $issueId = (string) UuidV7::generate();
        $this->connection->insert('issues', [
            'id' => $issueId,
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'number' => 1,
            'issue_key' => 'APP-1',
            'title' => 'Issue to migrate',
            'issue_type_id' => $typeId,
            'workflow_version_id' => $workflowVersionId,
            'status_id' => $this->statusId($statusCode),
            'reporter_membership_id' => $reporterMembershipId,
        ]);
        $this->connection->insert('issue_history', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'issue_id' => $issueId,
            'issue_version' => 1,
            'event_type' => 'ISSUE_CREATED',
            'to_status_id' => $this->statusId($statusCode),
        ]);

        return $issueId;
    }

    private function createProject(string $leadMembershipId): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('workflow-owner'),
            )->withParsedBody([
                'code' => 'APP',
                'name' => 'Workflow project',
                'lead_membership_id' => $leadMembershipId,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());

        return $this->stringAt($this->decode($response), ['project', 'id']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $path
     */
    private function stringAt(array $payload, array $path): string
    {
        $value = $payload;

        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $path
     */
    private function integerAt(array $payload, array $path): int
    {
        $value = $payload;

        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        self::assertIsInt($value);

        return $value;
    }

    private function insertUser(string $prefix): string
    {
        $id = (string) UuidV7::generate();
        $email = sprintf('%s@example.test', $prefix);
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => (new Argon2idPasswordHasher())->hash(self::PASSWORD),
            'display_name' => ucfirst(str_replace('-', ' ', $prefix)),
            'preferred_locale' => 'en',
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertTenant(string $slug): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => sprintf('%s-%s', $slug, substr($id, 0, 8)),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function addMembership(
        string $tenantId,
        string $userId,
        DefaultRole $role,
    ): string {
        $membershipId = (string) UuidV7::generate();
        $this->connection->insert('tenant_memberships', [
            'id' => $membershipId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'ACTIVE',
        ]);
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_membership_role_assignments (
                    tenant_id,
                    membership_id,
                    role_id,
                    granted_by_user_id
                )
                SELECT :tenant_id, :membership_id, id, :user_id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :role_code
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'user_id' => $userId,
                'role_code' => $role->value,
            ],
        );

        return $membershipId;
    }

    private function login(string $prefix): ResponseInterface
    {
        $response = $this->app->handle(
            $this->request('POST', '/api/v1/auth/login')
                ->withParsedBody([
                    'email' => sprintf('%s@example.test', $prefix),
                    'password' => self::PASSWORD,
                ]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    private function authenticatedRequest(
        string $method,
        string $uri,
        ResponseInterface $login,
    ): ServerRequestInterface {
        return $this->request($method, $uri)
            ->withCookieParams([
                'sova_session' => $this->cookieValue($login, 'sova_session'),
            ])
            ->withHeader('X-CSRF-Token', $this->cookieValue($login, 'sova_csrf'));
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $payload = json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        $result = [];

        foreach ($payload as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    private function cookieValue(
        ResponseInterface $response,
        string $name,
    ): string {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (
                preg_match(
                    sprintf('/(?:^|;\\s*)%s=([^;]+)/', preg_quote($name, '/')),
                    $header,
                    $matches,
                ) === 1
            ) {
                return urldecode($matches[1]);
            }
        }

        self::fail(sprintf('Cookie "%s" was not set.', $name));
    }
}
