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
use Sova\ProjectConfiguration\Domain\StatusCategory;
use Sova\ProjectConfiguration\Domain\WorkflowVersionState;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class IssueApiTest extends TestCase
{
    private const PASSWORD = 'A unique issue tracking passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $ownerMembershipId;
    private string $outsiderId;
    private string $tenantId;
    private string $foreignTenantId;
    private string $projectId;

    /**
     * @var array<string, string>
     */
    private array $issueTypes = [];

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
        $this->ownerId = $this->insertUser('issue-owner');
        $this->outsiderId = $this->insertUser('issue-outsider');
        $this->tenantId = $this->insertTenant('issue-primary');
        $this->foreignTenantId = $this->insertTenant('issue-foreign');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $this->ownerId,
            DefaultRole::TenantOwner,
        );
        $this->addMembership($this->tenantId, $this->outsiderId, DefaultRole::Member);
        $this->projectId = $this->createProject();
        $this->issueTypes = $this->loadIssueTypes();
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

    public function testProjectCreationProvisionsTheDefaultConfiguration(): void
    {
        self::assertSame(
            ['BUG', 'EPIC', 'STORY', 'SUBTASK', 'TASK'],
            $this->sortedKeys($this->issueTypes),
        );

        $login = $this->login('issue-owner');
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
        $payload = $this->decode($response);
        $statuses = $payload['statuses'] ?? null;
        self::assertIsArray($statuses);
        $codes = [];

        foreach ($statuses as $status) {
            self::assertIsArray($status);
            $code = $status['code'] ?? null;
            self::assertIsString($code);
            $codes[] = $code;
        }

        self::assertSame(['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'], $codes);
    }

    public function testIssuesGetSequentialProjectNumbersAndTheWorkflowInitialStatus(): void
    {
        $login = $this->login('issue-owner');
        $first = $this->decode($this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['TASK'],
            'title' => 'First task',
        ]));
        self::assertSame('APP-1', $this->stringAt($first, ['issue', 'key']));
        self::assertSame(1, $this->integerAt($first, ['issue', 'version']));
        self::assertSame(
            $this->ownerMembershipId,
            $this->stringAt($first, ['issue', 'reporter', 'membership_id']),
        );
        self::assertSame('OPEN', $this->stringAt($first, ['issue', 'status', 'code']));
        self::assertSame('TO_DO', $this->stringAt($first, ['issue', 'status', 'category']));

        $second = $this->decode($this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['BUG'],
            'title' => 'A bug',
            'priority' => 'CRITICAL',
        ]));
        self::assertSame('APP-2', $this->stringAt($second, ['issue', 'key']));
        self::assertSame('CRITICAL', $this->stringAt($second, ['issue', 'priority']));
    }

    public function testHierarchyRulesAreEnforced(): void
    {
        $login = $this->login('issue-owner');
        $orphan = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['SUBTASK'],
            'title' => 'Orphan sub-task',
        ]);
        self::assertSame(422, $orphan->getStatusCode());
        self::assertSame('HIERARCHY_INVALID', $this->decode($orphan)['code'] ?? null);

        $task = $this->createIssueId($login, 'TASK', 'Parent task');
        $subtask = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['SUBTASK'],
            'title' => 'Real sub-task',
            'parent_issue_id' => $task,
        ]);
        self::assertSame(201, $subtask->getStatusCode());

        $epic = $this->createIssueId($login, 'EPIC', 'An epic');
        $nestedEpic = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['EPIC'],
            'title' => 'Nested epic',
            'parent_issue_id' => $epic,
        ]);
        self::assertSame(422, $nestedEpic->getStatusCode());

        $story = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['STORY'],
            'title' => 'Story in the epic',
            'parent_issue_id' => $epic,
        ]);
        self::assertSame(201, $story->getStatusCode());

        $subtaskUnderEpic = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['SUBTASK'],
            'title' => 'Sub-task under an epic',
            'parent_issue_id' => $epic,
        ]);
        self::assertSame(422, $subtaskUnderEpic->getStatusCode());
    }

    public function testAParentFromAnotherProjectReadsAsMissing(): void
    {
        $login = $this->login('issue-owner');
        $foreignProject = $this->createProject('OTHER');
        $foreignTypes = $this->loadIssueTypes($foreignProject);
        $foreignTask = $this->stringAt(
            $this->decode($this->createIssue(
                $login,
                [
                    'issue_type_id' => $foreignTypes['TASK'],
                    'title' => 'Task in the other project',
                ],
                $foreignProject,
            )),
            ['issue', 'id'],
        );

        $response = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['SUBTASK'],
            'title' => 'Cross-project sub-task',
            'parent_issue_id' => $foreignTask,
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'PROJECT_RESOURCE_NOT_FOUND',
            $this->decode($response)['code'] ?? null,
        );
    }

    public function testTransitionsFollowTheWorkflowAndTheIssueVersion(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Transitioned task');

        $transitions = $this->transitions($login, $issueId);
        self::assertSame(['START'], $this->sortedKeys($transitions));

        $stale = $this->execute($login, $issueId, $transitions['START'], 99);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('ISSUE_VERSION_CONFLICT', $this->decode($stale)['code'] ?? null);

        $started = $this->execute($login, $issueId, $transitions['START'], 1);
        self::assertSame(200, $started->getStatusCode());
        $issue = $this->decode($started);
        self::assertSame('IN_PROGRESS', $this->stringAt($issue, ['issue', 'status', 'code']));
        self::assertSame(2, $this->integerAt($issue, ['issue', 'version']));

        $repeated = $this->execute($login, $issueId, $transitions['START'], 2);
        self::assertSame(422, $repeated->getStatusCode());
        self::assertSame(
            'TRANSITION_NOT_AVAILABLE',
            $this->decode($repeated)['code'] ?? null,
        );

        $next = $this->transitions($login, $issueId);
        self::assertSame(['RESOLVE', 'STOP'], $this->sortedKeys($next));

        $resolved = $this->execute($login, $issueId, $next['RESOLVE'], 2);
        self::assertSame(200, $resolved->getStatusCode());
        self::assertSame(
            'DONE',
            $this->stringAt($this->decode($resolved), ['issue', 'status', 'category']),
        );

        $history = $this->connection->fetchFirstColumn(
            'SELECT event_type FROM issue_history WHERE issue_id = :id ORDER BY issue_version',
            ['id' => $issueId],
        );
        self::assertSame(
            ['ISSUE_CREATED', 'ISSUE_TRANSITIONED', 'ISSUE_TRANSITIONED'],
            $history,
        );

        $outbox = $this->connection->fetchFirstColumn(
            'SELECT event_name FROM outbox_events WHERE aggregate_id = :id ORDER BY sequence_number',
            ['id' => $issueId],
        );
        self::assertSame(
            ['ISSUE_CREATED', 'ISSUE_TRANSITIONED', 'ISSUE_TRANSITIONED'],
            $outbox,
        );
    }

    public function testTransitionResolutionRulesRunAtTransitionTime(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Resolution flow');

        $started = $this->execute($login, $issueId, $this->transitions($login, $issueId)['START'], 1);
        self::assertSame(200, $started->getStatusCode());

        // A resolution_required validator with no set_resolution action forces
        // the client to supply a resolution; set_resolved_at stamps the time.
        $this->insertTransitionRule('RESOLVE', 'VALIDATOR', 'resolution_required', [], 10);
        $this->insertTransitionRule('RESOLVE', 'ACTION', 'set_resolved_at', [], 20);

        $entries = $this->transitionEntries($login, $issueId);
        self::assertSame(['resolution'], $entries['RESOLVE']['required_fields'] ?? null);
        self::assertSame([], $entries['STOP']['required_fields'] ?? null);

        $ids = $this->transitions($login, $issueId);
        $missing = $this->execute($login, $issueId, $ids['RESOLVE'], 2);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('ISSUE_TRANSITION_INVALID', $this->decode($missing)['code'] ?? null);

        $resolved = $this->execute($login, $issueId, $ids['RESOLVE'], 2, [
            'fields' => ['resolution' => 'Fixed'],
        ]);
        self::assertSame(200, $resolved->getStatusCode());
        $issue = $this->decode($resolved);
        self::assertSame('RESOLVED', $this->stringAt($issue, ['issue', 'status', 'code']));
        self::assertSame('Fixed', $this->stringAt($issue, ['issue', 'resolution']));
        self::assertNotSame('', $this->stringAt($issue, ['issue', 'resolved_at']));
        self::assertSame(3, $this->integerAt($issue, ['issue', 'version']));

        // Reopening clears both fields via clear_resolution and clear_resolved_at.
        $this->insertTransitionRule('REOPEN', 'ACTION', 'clear_resolution', [], 10);
        $this->insertTransitionRule('REOPEN', 'ACTION', 'clear_resolved_at', [], 20);

        $reopened = $this->execute($login, $issueId, $this->transitions($login, $issueId)['REOPEN'], 3);
        self::assertSame(200, $reopened->getStatusCode());
        $issue = $this->decode($reopened);
        self::assertSame('OPEN', $this->stringAt($issue, ['issue', 'status', 'code']));
        $this->assertNullAt($issue, ['issue', 'resolution']);
        $this->assertNullAt($issue, ['issue', 'resolved_at']);
    }

    public function testTransitionSetResolutionActionFixesResolutionWithoutClientInput(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Fixed resolution');
        $this->execute($login, $issueId, $this->transitions($login, $issueId)['START'], 1);

        // A set_resolution action supersedes resolution_required, so no field is
        // asked of the client and the configured value is stored.
        $this->insertTransitionRule('RESOLVE', 'VALIDATOR', 'resolution_required', [], 10);
        $this->insertTransitionRule('RESOLVE', 'ACTION', 'set_resolution', ['resolution' => 'WontFix'], 20);

        $entries = $this->transitionEntries($login, $issueId);
        self::assertSame([], $entries['RESOLVE']['required_fields'] ?? null);

        $resolved = $this->execute($login, $issueId, $this->transitions($login, $issueId)['RESOLVE'], 2);
        self::assertSame(200, $resolved->getStatusCode());
        self::assertSame('WontFix', $this->stringAt($this->decode($resolved), ['issue', 'resolution']));
    }

    public function testATenantMemberWithoutAProjectRoleIsRejected(): void
    {
        $ownerLogin = $this->login('issue-owner');
        $issueId = $this->createIssueId($ownerLogin, 'TASK', 'Private work');
        $transitions = $this->transitions($ownerLogin, $issueId);

        $outsiderLogin = $this->login('issue-outsider');
        $list = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/issues',
                $this->tenantId,
                $this->projectId,
            ),
            $outsiderLogin,
        ));
        self::assertSame(403, $list->getStatusCode());

        $create = $this->createIssue($outsiderLogin, [
            'issue_type_id' => $this->issueTypes['TASK'],
            'title' => 'Not allowed',
        ]);
        self::assertSame(403, $create->getStatusCode());

        // The permission is checked before the workflow, so the response never
        // reveals which transitions the issue currently offers.
        $transition = $this->execute($outsiderLogin, $issueId, $transitions['START'], 1);
        self::assertSame(403, $transition->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($transition)['code'] ?? null);
    }

    public function testIssuesAreIsolatedPerTenant(): void
    {
        $ownerLogin = $this->login('issue-owner');
        $issueId = $this->createIssueId($ownerLogin, 'TASK', 'Isolated');

        $foreignUserId = $this->insertUser('issue-foreign-owner');
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $this->foreignTenantId,
            'user_id' => $foreignUserId,
            'status' => 'ACTIVE',
        ]);
        $foreignLogin = $this->login('issue-foreign-owner');

        $read = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s', $this->foreignTenantId, $issueId),
            $foreignLogin,
        ));

        self::assertSame(404, $read->getStatusCode());
        self::assertSame('ISSUE_NOT_FOUND', $this->decode($read)['code'] ?? null);
    }

    public function testChangeIssueTypeMapsStatusAndRecordsHistory(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Type change target');

        $response = $this->changeType($login, $issueId, $this->issueTypes['BUG'], 1);
        self::assertSame(200, $response->getStatusCode());
        $issue = $this->decode($response);
        self::assertSame('BUG', $this->stringAt($issue, ['issue', 'issue_type', 'code']));
        // The default template shares one workflow, so the open status maps
        // straight across and the client never supplies a target status.
        self::assertSame('OPEN', $this->stringAt($issue, ['issue', 'status', 'code']));
        self::assertSame(2, $this->integerAt($issue, ['issue', 'version']));

        $history = $this->connection->fetchAllAssociative(
            'SELECT event_type, metadata FROM issue_history WHERE issue_id = :id ORDER BY issue_version',
            ['id' => $issueId],
        );
        self::assertSame(
            ['ISSUE_CREATED', 'ISSUE_TYPE_CHANGED'],
            array_map(static fn(array $row): mixed => $row['event_type'], $history),
        );
        $rawMetadata = $history[1]['metadata'] ?? null;
        self::assertIsString($rawMetadata);
        $metadata = json_decode($rawMetadata, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame($this->issueTypes['TASK'], $metadata['from_issue_type_id'] ?? null);
        self::assertSame($this->issueTypes['BUG'], $metadata['to_issue_type_id'] ?? null);

        $outbox = $this->connection->fetchFirstColumn(
            'SELECT event_name FROM outbox_events WHERE aggregate_id = :id ORDER BY sequence_number',
            ['id' => $issueId],
        );
        self::assertSame(['ISSUE_CREATED', 'ISSUE_TYPE_CHANGED'], $outbox);
    }

    public function testChangeIssueTypeRejectsTheSameType(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Same type');

        $response = $this->changeType($login, $issueId, $this->issueTypes['TASK'], 1);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ISSUE_TYPE_UNCHANGED', $this->decode($response)['code'] ?? null);
    }

    public function testChangeIssueTypeRejectsAnArchivedTarget(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Archived target');
        $this->connection->executeStatement(
            "UPDATE project_issue_types SET status = 'ARCHIVED' WHERE id = :id",
            ['id' => $this->issueTypes['BUG']],
        );

        $response = $this->changeType($login, $issueId, $this->issueTypes['BUG'], 1);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('ISSUE_TYPE_INVALID', $this->decode($response)['code'] ?? null);
    }

    public function testChangeIssueTypeRejectsAStaleVersion(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Stale version');

        $response = $this->changeType($login, $issueId, $this->issueTypes['BUG'], 99);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ISSUE_VERSION_CONFLICT', $this->decode($response)['code'] ?? null);
    }

    public function testChangeIssueTypeRejectsAChildThatWouldNoLongerFit(): void
    {
        $login = $this->login('issue-owner');
        $taskId = $this->createIssueId($login, 'TASK', 'Parent task');
        $subtask = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['SUBTASK'],
            'title' => 'A sub-task',
            'parent_issue_id' => $taskId,
        ]);
        self::assertSame(201, $subtask->getStatusCode());

        // An epic cannot parent a sub-task, so the existing child blocks it.
        $response = $this->changeType($login, $taskId, $this->issueTypes['EPIC'], 1);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('HIERARCHY_INVALID', $this->decode($response)['code'] ?? null);

        $version = $this->connection->fetchOne(
            'SELECT version FROM issues WHERE id = :id',
            ['id' => $taskId],
        );
        self::assertSame(1, $version);
    }

    public function testChangeIssueTypeRejectsAParentThatTheTargetForbids(): void
    {
        $login = $this->login('issue-owner');
        $taskId = $this->createIssueId($login, 'TASK', 'Standard parent');
        $subtaskId = $this->stringAt(
            $this->decode($this->createIssue($login, [
                'issue_type_id' => $this->issueTypes['SUBTASK'],
                'title' => 'Sub-task with a parent',
                'parent_issue_id' => $taskId,
            ])),
            ['issue', 'id'],
        );

        // An epic must be a root, but this issue already has a parent.
        $response = $this->changeType($login, $subtaskId, $this->issueTypes['EPIC'], 1);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('HIERARCHY_INVALID', $this->decode($response)['code'] ?? null);
    }

    public function testChangeIssueTypeRequiresAProjectRole(): void
    {
        $ownerLogin = $this->login('issue-owner');
        $issueId = $this->createIssueId($ownerLogin, 'TASK', 'Guarded type change');

        $outsiderLogin = $this->login('issue-outsider');
        $response = $this->changeType($outsiderLogin, $issueId, $this->issueTypes['BUG'], 1);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($response)['code'] ?? null);
    }

    public function testChangeIssueTypeIsIsolatedPerTenant(): void
    {
        $ownerLogin = $this->login('issue-owner');
        $issueId = $this->createIssueId($ownerLogin, 'TASK', 'Isolated type change');

        $foreignUserId = $this->insertUser('issue-foreign-typer');
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $this->foreignTenantId,
            'user_id' => $foreignUserId,
            'status' => 'ACTIVE',
        ]);
        $foreignLogin = $this->login('issue-foreign-typer');

        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/%s/type', $this->foreignTenantId, $issueId),
                $foreignLogin,
            )->withParsedBody([
                'target_issue_type_id' => $this->issueTypes['BUG'],
                'expected_issue_version' => 1,
            ]),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('ISSUE_NOT_FOUND', $this->decode($response)['code'] ?? null);
    }

    public function testChangeIssueTypeRequiresATargetStatusWhenMappingIsAmbiguous(): void
    {
        $login = $this->login('issue-owner');
        $issueId = $this->createIssueId($login, 'TASK', 'Ambiguous mapping');
        $statuses = $this->remapTypeToDisjointWorkflow('BUG');

        // The bug workflow has no OPEN status, so the change needs a target.
        $missing = $this->changeType($login, $issueId, $this->issueTypes['BUG'], 1);
        self::assertSame(409, $missing->getStatusCode());
        self::assertSame(
            'ISSUE_TYPE_STATUS_MAPPING_REQUIRED',
            $this->decode($missing)['code'] ?? null,
        );

        // A status from the wrong workflow is rejected before anything changes.
        $wrong = $this->changeType($login, $issueId, $this->issueTypes['BUG'], 1, [
            'target_status_id' => $this->issueTypes['STORY'],
        ]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame('ISSUE_TYPE_STATUS_INVALID', $this->decode($wrong)['code'] ?? null);

        $moved = $this->changeType($login, $issueId, $this->issueTypes['BUG'], 1, [
            'target_status_id' => $statuses['TRIAGE'],
        ]);
        self::assertSame(200, $moved->getStatusCode());
        $issue = $this->decode($moved);
        self::assertSame('BUG', $this->stringAt($issue, ['issue', 'issue_type', 'code']));
        self::assertSame('TRIAGE', $this->stringAt($issue, ['issue', 'status', 'code']));
        self::assertSame(2, $this->integerAt($issue, ['issue', 'version']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createIssue(
        ResponseInterface $login,
        array $payload,
        ?string $projectId = null,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/issues',
                    $this->tenantId,
                    $projectId ?? $this->projectId,
                ),
                $login,
            )->withParsedBody($payload),
        );
    }

    private function createIssueId(
        ResponseInterface $login,
        string $typeCode,
        string $title,
    ): string {
        $response = $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes[$typeCode],
            'title' => $title,
        ]);
        self::assertSame(201, $response->getStatusCode());

        return $this->stringAt($this->decode($response), ['issue', 'id']);
    }

    /**
     * @return array<string, string> transition code to identifier
     */
    private function transitions(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/transitions', $this->tenantId, $issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());
        $transitions = $this->decode($response)['transitions'] ?? null;
        self::assertIsArray($transitions);

        $byCode = [];

        foreach ($transitions as $transition) {
            self::assertIsArray($transition);
            $code = $transition['code'] ?? null;
            $id = $transition['id'] ?? null;
            self::assertIsString($code);
            self::assertIsString($id);
            $byCode[$code] = $id;
        }

        return $byCode;
    }

    /**
     * @param array<string, mixed> $extra additional request body fields
     */
    private function execute(
        ResponseInterface $login,
        string $issueId,
        string $transitionId,
        int $expectedVersion,
        array $extra = [],
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/issues/%s/transitions/%s',
                    $this->tenantId,
                    $issueId,
                    $transitionId,
                ),
                $login,
            )->withParsedBody(['expected_issue_version' => $expectedVersion] + $extra),
        );
    }

    /**
     * @param array<string, mixed> $extra additional request body fields
     */
    private function changeType(
        ResponseInterface $login,
        string $issueId,
        string $targetIssueTypeId,
        int $expectedVersion,
        array $extra = [],
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/%s/type', $this->tenantId, $issueId),
                $login,
            )->withParsedBody([
                'target_issue_type_id' => $targetIssueTypeId,
                'expected_issue_version' => $expectedVersion,
            ] + $extra),
        );
    }

    /**
     * Publishes a second workflow whose statuses do not overlap the default one
     * and points the given issue type at it, so a type change onto that type
     * cannot map the current status automatically.
     *
     * @return array<string, string> status code to identifier
     */
    private function remapTypeToDisjointWorkflow(string $typeCode): array
    {
        $statuses = [
            'TRIAGE' => $this->insertProjectStatus('TRIAGE', 'Triage', StatusCategory::ToDo, 110),
            'FIXED' => $this->insertProjectStatus('FIXED', 'Fixed', StatusCategory::Done, 120),
        ];

        $workflowId = (string) UuidV7::generate();
        $this->connection->insert('project_workflows', [
            'id' => $workflowId,
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'name' => sprintf('%s workflow', $typeCode),
        ]);

        $versionId = (string) UuidV7::generate();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_workflow_versions (
                    id, tenant_id, project_id, workflow_id, version_number,
                    state, initial_status_id, published_at
                ) VALUES (
                    :id, :tenant_id, :project_id, :workflow_id, 1,
                    :state, :initial_status_id, CURRENT_TIMESTAMP
                )
                SQL,
            [
                'id' => $versionId,
                'tenant_id' => $this->tenantId,
                'project_id' => $this->projectId,
                'workflow_id' => $workflowId,
                'state' => WorkflowVersionState::Published->value,
                'initial_status_id' => $statuses['TRIAGE'],
            ],
        );

        $position = 10;

        foreach ($statuses as $statusId) {
            $this->connection->insert('workflow_version_statuses', [
                'tenant_id' => $this->tenantId,
                'project_id' => $this->projectId,
                'workflow_version_id' => $versionId,
                'status_id' => $statusId,
                'position' => $position,
            ]);
            $position += 10;
        }

        $this->connection->executeStatement(
            'UPDATE project_workflows SET active_version_id = :version WHERE id = :workflow',
            ['version' => $versionId, 'workflow' => $workflowId],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_issue_type_workflows
                SET workflow_id = :workflow
                WHERE project_id = :project_id AND issue_type_id = :issue_type_id
                SQL,
            [
                'workflow' => $workflowId,
                'project_id' => $this->projectId,
                'issue_type_id' => $this->issueTypes[$typeCode],
            ],
        );

        return $statuses;
    }

    private function insertProjectStatus(
        string $code,
        string $name,
        StatusCategory $category,
        int $position,
    ): string {
        $id = (string) UuidV7::generate();
        $this->connection->insert('project_statuses', [
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'code' => $code,
            'name' => $name,
            'category' => $category->value,
            'position' => $position,
        ]);

        return $id;
    }

    /**
     * Like {@see transitions()} but returns each transition's full serialized
     * shape so a test can assert on fields such as `required_fields`.
     *
     * @return array<string, array<string, mixed>> transition code to entry
     */
    private function transitionEntries(ResponseInterface $login, string $issueId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issues/%s/transitions', $this->tenantId, $issueId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());
        $transitions = $this->decode($response)['transitions'] ?? null;
        self::assertIsArray($transitions);

        $byCode = [];

        foreach ($transitions as $transition) {
            self::assertIsArray($transition);
            $code = $transition['code'] ?? null;
            self::assertIsString($code);
            $entry = [];

            foreach ($transition as $key => $value) {
                self::assertIsString($key);
                $entry[$key] = $value;
            }

            $byCode[$code] = $entry;
        }

        return $byCode;
    }

    /**
     * Attaches a stored transition rule to a workflow transition identified by
     * its code, mirroring what the configuration publish flow would persist.
     *
     * @param array<string, mixed> $config
     */
    private function insertTransitionRule(
        string $transitionCode,
        string $type,
        string $key,
        array $config,
        int $position,
    ): void {
        $transitionId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM project_workflow_transitions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND code = :code
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'project_id' => $this->projectId,
                'code' => $transitionCode,
            ],
        );
        self::assertIsString($transitionId);

        $this->connection->insert('workflow_transition_rules', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'transition_id' => $transitionId,
            'rule_type' => $type,
            'rule_key' => $key,
            'configuration' => json_encode($config, JSON_THROW_ON_ERROR),
            'position' => $position,
        ]);
    }

    private function createProject(string $code = 'APP'): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('issue-owner'),
            )->withParsedBody([
                'code' => $code,
                'name' => sprintf('Project %s', $code),
                'lead_membership_id' => $this->ownerMembershipId,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());

        return $this->stringAt($this->decode($response), ['project', 'id']);
    }

    /**
     * @return array<string, string> issue type code to identifier
     */
    private function loadIssueTypes(?string $projectId = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT code, id
                FROM project_issue_types
                WHERE tenant_id = :tenant_id AND project_id = :project_id
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'project_id' => $projectId ?? $this->projectId,
            ],
        );
        $types = [];

        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            $id = $row['id'] ?? null;
            self::assertIsString($code);
            self::assertIsString($id);
            $types[$code] = $id;
        }

        return $types;
    }

    /**
     * Reads a nested string from a decoded response, narrowing the mixed value
     * that JSON decoding produces.
     *
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

    /**
     * Asserts a nested value is present and null, narrowing the mixed value
     * that JSON decoding produces.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $path
     */
    private function assertNullAt(array $payload, array $path): void
    {
        $value = $payload;

        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        self::assertNull($value);
    }

    /**
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
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
