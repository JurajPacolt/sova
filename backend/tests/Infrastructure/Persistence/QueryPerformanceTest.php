<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Persistence;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use JsonException;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Sova\Dashboards\Infrastructure\Persistence\DoctrineDashboardRepository;
use Sova\Issues\Application\Search\CompiledQuery;
use Sova\Issues\Application\Search\CompiledSort;
use Sova\Issues\Application\Search\SearchScope;
use Sova\Issues\Domain\QueryLanguage\SortDirection;
use Sova\Issues\Infrastructure\Persistence\DoctrineIssueSearchRepository;
use Sova\Projects\Infrastructure\Persistence\DoctrineProjectRepository;
use Sova\Shared\Application\Audit\AuditQuery;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Shared\Infrastructure\Persistence\ConnectionFactory;
use Sova\Shared\Infrastructure\Persistence\DoctrineSecurityAuditReader;
use Sova\Tests\Support\QueryCountingLogger;

/**
 * Repeatable database performance smoke over cardinalities large enough for
 * PostgreSQL's planner to make meaningful index choices.
 *
 * This is deliberately not a production p95 benchmark: CI hardware, shared
 * runners and warm caches cannot prove that SLO. It protects the structural
 * facts that do travel between environments — critical predicates have usable
 * indexes and list hydration does not add one SQL statement per returned row.
 */
final class QueryPerformanceTest extends TestCase
{
    private const int ISSUE_COUNT = 20_000;
    private const int MEMBER_COUNT = 100;
    private const int PROJECT_COUNT = 50;
    private const int DASHBOARD_COUNT = 30;
    private const int AUDIT_COUNT = 250;
    private const float SQL_SMOKE_LIMIT_MS = 500.0;

    private const string TENANT_ID = '70000000-0000-7000-8000-000000000001';
    private const string USER_ID = '10000000-0000-7000-8000-000000000001';
    private const string MEMBERSHIP_ID = '11000000-0000-7000-8000-000000000001';
    private const string PROJECT_ID = '20000000-0000-7000-8000-000000000001';
    private const string SECOND_PROJECT_ID = '21000000-0000-7000-8000-000000000002';
    private const string ISSUE_TYPE_ID = '30000000-0000-7000-8000-000000000001';
    private const string SECOND_ISSUE_TYPE_ID = '31000000-0000-7000-8000-000000000002';
    private const string STATUS_ID = '40000000-0000-7000-8000-000000000001';
    private const string SECOND_STATUS_ID = '41000000-0000-7000-8000-000000000002';
    private const string WORKFLOW_ID = '50000000-0000-7000-8000-000000000001';
    private const string SECOND_WORKFLOW_ID = '51000000-0000-7000-8000-000000000002';
    private const string WORKFLOW_VERSION_ID = '60000000-0000-7000-8000-000000000001';
    private const string SECOND_WORKFLOW_VERSION_ID =
        '61000000-0000-7000-8000-000000000002';

    private Connection $connection;
    private Settings $settings;
    private QueryCountingLogger $queries;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 3));
        $settings = $app->getContainer()->get(Settings::class);

        if (!$settings instanceof Settings) {
            self::fail('The container must provide application settings.');
        }

        $this->settings = $settings;
        $this->queries = new QueryCountingLogger();
        $this->connection = ConnectionFactory::create(
            $settings,
            [new LoggingMiddleware($this->queries)],
        );
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            "SELECT set_config('sova.tenant_id', :tenant_id, true)",
            ['tenant_id' => self::TENANT_ID],
        );
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if (isset($this->connection)) {
            // The performance fixture temporarily teaches the planner about
            // 20k uncommitted issues. Restore statistics for the real, rolled
            // back table so this test does not distort later local work.
            $this->connection->executeStatement('ANALYZE issues');
            $this->connection->close();
        }
    }

    public function testCriticalIssuePredicatesUseTheirMeasuredIndexes(): void
    {
        // Twenty thousand rows are deliberately small enough for a local
        // regression suite. Depending on PostgreSQL cost settings, a warm
        // parallel sequential scan can therefore be a legitimate choice for a
        // LIMIT 100 full-text query. This test checks the structural contract:
        // every compiler expression still has a usable matching index. With
        // sequential scans discouraged, a missing or mismatched index still
        // falls back to Seq Scan at a prohibitive cost and fails below.
        $this->connection->executeStatement('SET LOCAL enable_seqscan = off');

        $reporter = $this->explain(
            <<<'SQL'
                SELECT issue.id
                FROM issues issue
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                    AND issue.reporter_membership_id = :reporter_id
                ORDER BY issue.id ASC
                LIMIT 100
                SQL,
            [
                'tenant_id' => self::TENANT_ID,
                'project_id' => self::PROJECT_ID,
                'reporter_id' => self::MEMBERSHIP_ID,
            ],
        );
        $this->assertIndexedIssuePlan($reporter, 'idx_issues_project_reporter');

        $priority = $this->explain(
            <<<'SQL'
                SELECT issue.id
                FROM issues issue
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                ORDER BY
                    (
                        CASE issue.priority
                            WHEN 'LOW' THEN 1
                            WHEN 'NORMAL' THEN 2
                            WHEN 'HIGH' THEN 3
                            WHEN 'CRITICAL' THEN 4
                            ELSE 0
                        END
                    ) DESC,
                    issue.id ASC
                LIMIT 100
                SQL,
            ['tenant_id' => self::TENANT_ID, 'project_id' => self::PROJECT_ID],
        );
        $this->assertIndexedIssuePlan($priority, 'idx_issues_project_priority_rank');

        $updated = $this->explain(
            <<<'SQL'
                SELECT issue.id
                FROM issues issue
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                ORDER BY issue.updated_at DESC, issue.id ASC
                LIMIT 100
                SQL,
            ['tenant_id' => self::TENANT_ID, 'project_id' => self::PROJECT_ID],
        );
        $this->assertIndexedIssuePlan($updated, 'idx_issues_project_updated');

        $fulltext = $this->explain(
            <<<'SQL'
                SELECT issue.id
                FROM issues issue
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                    AND issue.search_vector
                        @@ websearch_to_tsquery('simple', :needle)
                LIMIT 100
                SQL,
            [
                'tenant_id' => self::TENANT_ID,
                'project_id' => self::PROJECT_ID,
                'needle' => '"performance needle"',
            ],
        );
        $this->assertSpecializedOrScopedIssuePlan(
            $fulltext,
            'idx_issues_fulltext',
        );

        $title = $this->explain(
            <<<'SQL'
                SELECT issue.id
                FROM issues issue
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id = :project_id
                    AND issue.title ILIKE :needle ESCAPE '\'
                LIMIT 100
                SQL,
            [
                'tenant_id' => self::TENANT_ID,
                'project_id' => self::PROJECT_ID,
                'needle' => '%performance needle%',
            ],
        );
        $this->assertSpecializedOrScopedIssuePlan(
            $title,
            'idx_issues_title_trigram',
        );
    }

    public function testMainListsKeepAConstantDatabaseQueryBudget(): void
    {
        $projects = new DoctrineProjectRepository($this->connection);
        $this->queries->reset();
        self::assertCount(
            self::PROJECT_COUNT,
            $projects->listForTenant(self::TENANT_ID, self::USER_ID),
        );
        $this->assertQueryBudget(1, 'project listing');

        $dashboards = new DoctrineDashboardRepository($this->connection);
        $this->queries->reset();
        self::assertCount(
            self::DASHBOARD_COUNT,
            $dashboards->listOwned(self::TENANT_ID, self::MEMBERSHIP_ID),
        );
        $this->assertQueryBudget(1, 'dashboard listing');

        $search = new DoctrineIssueSearchRepository($this->connection, $this->settings);
        $this->queries->reset();
        $rows = $search->search(
            new SearchScope(
                self::TENANT_ID,
                self::USER_ID,
                [self::PROJECT_ID],
                1,
            ),
            new CompiledQuery(
                '',
                [],
                [],
                [
                    new CompiledSort(
                        'updated',
                        'issue.updated_at',
                        'sort_0',
                        SortDirection::Descending,
                        true,
                        false,
                    ),
                ],
            ),
            null,
            100,
        );
        self::assertCount(100, $rows);
        // One SET LOCAL for the safety timeout and one bounded SELECT.
        $this->assertQueryBudget(2, 'issue search');

        $audit = new DoctrineSecurityAuditReader($this->connection);
        $this->queries->reset();
        $page = $audit->page(
            new AuditQuery(100, null, null, null, null, null, null, null),
            self::TENANT_ID,
        );
        self::assertCount(100, $page->events);
        self::assertNotNull($page->nextCursor);
        $this->assertQueryBudget(1, 'security-audit page');
    }

    private function seed(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenants (id, name, slug, status)
                VALUES (:tenant_id, 'Performance tenant', 'performance-tenant', 'ACTIVE')
                SQL,
            ['tenant_id' => self::TENANT_ID],
        );

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
                SELECT
                    (
                        '10000000-0000-7000-8000-'
                        || LPAD(TO_HEX(member_number), 12, '0')
                    )::uuid,
                    'performance-' || member_number || '@example.test',
                    'performance-' || member_number || '@example.test',
                    'not-used-by-performance-test',
                    'Performance member ' || member_number,
                    'en',
                    'ACTIVE',
                    CURRENT_TIMESTAMP
                FROM generate_series(1, :member_count) member_number
                SQL,
            ['member_count' => self::MEMBER_COUNT],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_memberships (id, tenant_id, user_id, status)
                SELECT
                    (
                        '11000000-0000-7000-8000-'
                        || LPAD(TO_HEX(member_number), 12, '0')
                    )::uuid,
                    :tenant_id,
                    (
                        '10000000-0000-7000-8000-'
                        || LPAD(TO_HEX(member_number), 12, '0')
                    )::uuid,
                    'ACTIVE'
                FROM generate_series(1, :member_count) member_number
                SQL,
            ['tenant_id' => self::TENANT_ID, 'member_count' => self::MEMBER_COUNT],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO projects (
                    id,
                    tenant_id,
                    code,
                    name,
                    description,
                    visibility,
                    status,
                    lead_membership_id,
                    created_by_user_id
                )
                SELECT
                    CASE
                        WHEN project_number = 1 THEN :main_project_id::uuid
                        ELSE (
                            '21000000-0000-7000-8000-'
                            || LPAD(TO_HEX(project_number), 12, '0')
                        )::uuid
                    END,
                    :tenant_id,
                    'P' || LPAD(project_number::text, 4, '0'),
                    'Performance project ' || project_number,
                    '',
                    'TENANT',
                    'ACTIVE',
                    :membership_id,
                    :user_id
                FROM generate_series(1, :project_count) project_number
                SQL,
            [
                'main_project_id' => self::PROJECT_ID,
                'tenant_id' => self::TENANT_ID,
                'membership_id' => self::MEMBERSHIP_ID,
                'user_id' => self::USER_ID,
                'project_count' => self::PROJECT_COUNT,
            ],
        );

        $this->connection->insert('project_issue_types', [
            'id' => self::ISSUE_TYPE_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::PROJECT_ID,
            'code' => 'TASK',
            'name' => 'Task',
        ]);
        $this->connection->insert('project_statuses', [
            'id' => self::STATUS_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::PROJECT_ID,
            'code' => 'OPEN',
            'name' => 'Open',
            'category' => 'TO_DO',
        ]);
        $this->connection->insert('project_workflows', [
            'id' => self::WORKFLOW_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::PROJECT_ID,
            'name' => 'Performance workflow',
        ]);
        $this->connection->insert('project_workflow_versions', [
            'id' => self::WORKFLOW_VERSION_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::PROJECT_ID,
            'workflow_id' => self::WORKFLOW_ID,
            'version_number' => 1,
            'state' => 'PUBLISHED',
            'initial_status_id' => self::STATUS_ID,
            'published_at' => '2026-07-29 00:00:00+00',
        ]);
        $this->connection->update(
            'project_workflows',
            ['active_version_id' => self::WORKFLOW_VERSION_ID],
            ['id' => self::WORKFLOW_ID],
        );
        $this->connection->insert('project_issue_types', [
            'id' => self::SECOND_ISSUE_TYPE_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::SECOND_PROJECT_ID,
            'code' => 'TASK',
            'name' => 'Task',
        ]);
        $this->connection->insert('project_statuses', [
            'id' => self::SECOND_STATUS_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::SECOND_PROJECT_ID,
            'code' => 'OPEN',
            'name' => 'Open',
            'category' => 'TO_DO',
        ]);
        $this->connection->insert('project_workflows', [
            'id' => self::SECOND_WORKFLOW_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::SECOND_PROJECT_ID,
            'name' => 'Second performance workflow',
        ]);
        $this->connection->insert('project_workflow_versions', [
            'id' => self::SECOND_WORKFLOW_VERSION_ID,
            'tenant_id' => self::TENANT_ID,
            'project_id' => self::SECOND_PROJECT_ID,
            'workflow_id' => self::SECOND_WORKFLOW_ID,
            'version_number' => 1,
            'state' => 'PUBLISHED',
            'initial_status_id' => self::SECOND_STATUS_ID,
            'published_at' => '2026-07-29 00:00:00+00',
        ]);
        $this->connection->update(
            'project_workflows',
            ['active_version_id' => self::SECOND_WORKFLOW_VERSION_ID],
            ['id' => self::SECOND_WORKFLOW_ID],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO issues (
                    id,
                    tenant_id,
                    project_id,
                    number,
                    issue_key,
                    title,
                    description,
                    issue_type_id,
                    workflow_version_id,
                    status_id,
                    reporter_membership_id,
                    priority,
                    created_by_user_id,
                    created_at,
                    updated_at
                )
                SELECT
                    (
                        '90000000-0000-7000-8000-'
                        || LPAD(TO_HEX(issue_number), 12, '0')
                    )::uuid,
                    :tenant_id,
                    CASE
                        WHEN issue_number % 2 = 0 THEN :project_id::uuid
                        ELSE :second_project_id::uuid
                    END,
                    issue_number,
                    CASE
                        WHEN issue_number % 2 = 0
                            THEN 'P0001-' || issue_number
                        ELSE 'P0002-' || issue_number
                    END,
                    CASE
                        WHEN issue_number % 100 = 0
                            THEN 'Rare performance needle ' || issue_number
                        ELSE 'Ordinary issue ' || issue_number
                    END,
                    CASE
                        WHEN issue_number % 100 = 0
                            THEN 'Contains the performance needle for index measurement.'
                        ELSE 'Ordinary performance fixture text.'
                    END,
                    CASE
                        WHEN issue_number % 2 = 0 THEN :issue_type_id::uuid
                        ELSE :second_issue_type_id::uuid
                    END,
                    CASE
                        WHEN issue_number % 2 = 0 THEN :workflow_version_id::uuid
                        ELSE :second_workflow_version_id::uuid
                    END,
                    CASE
                        WHEN issue_number % 2 = 0 THEN :status_id::uuid
                        ELSE :second_status_id::uuid
                    END,
                    (
                        '11000000-0000-7000-8000-'
                        || LPAD(
                            TO_HEX(((issue_number - 1) % :member_count) + 1),
                            12,
                            '0'
                        )
                    )::uuid,
                    CASE issue_number % 4
                        WHEN 0 THEN 'LOW'
                        WHEN 1 THEN 'NORMAL'
                        WHEN 2 THEN 'HIGH'
                        ELSE 'CRITICAL'
                    END,
                    :user_id,
                    CURRENT_TIMESTAMP - MAKE_INTERVAL(secs => issue_number),
                    CURRENT_TIMESTAMP - MAKE_INTERVAL(secs => issue_number)
                FROM generate_series(1, :issue_count) issue_number
                SQL,
            [
                'tenant_id' => self::TENANT_ID,
                'project_id' => self::PROJECT_ID,
                'second_project_id' => self::SECOND_PROJECT_ID,
                'issue_type_id' => self::ISSUE_TYPE_ID,
                'second_issue_type_id' => self::SECOND_ISSUE_TYPE_ID,
                'workflow_version_id' => self::WORKFLOW_VERSION_ID,
                'second_workflow_version_id' => self::SECOND_WORKFLOW_VERSION_ID,
                'status_id' => self::STATUS_ID,
                'second_status_id' => self::SECOND_STATUS_ID,
                'member_count' => self::MEMBER_COUNT,
                'user_id' => self::USER_ID,
                'issue_count' => self::ISSUE_COUNT,
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO dashboards (
                    id,
                    tenant_id,
                    owner_membership_id,
                    name,
                    normalized_name,
                    position,
                    is_default
                )
                SELECT
                    (
                        '80000000-0000-7000-8000-'
                        || LPAD(TO_HEX(dashboard_number), 12, '0')
                    )::uuid,
                    :tenant_id,
                    :membership_id,
                    'Performance dashboard ' || dashboard_number,
                    'performance dashboard ' || dashboard_number,
                    dashboard_number - 1,
                    dashboard_number = 1
                FROM generate_series(1, :dashboard_count) dashboard_number
                SQL,
            [
                'tenant_id' => self::TENANT_ID,
                'membership_id' => self::MEMBERSHIP_ID,
                'dashboard_count' => self::DASHBOARD_COUNT,
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO security_audit_events (
                    id,
                    actor_user_id,
                    tenant_id,
                    event_type,
                    outcome,
                    reason_code,
                    request_id,
                    occurred_at
                )
                SELECT
                    (
                        'a0000000-0000-7000-8000-'
                        || LPAD(TO_HEX(event_number), 12, '0')
                    )::uuid,
                    :user_id,
                    :tenant_id,
                    'PERFORMANCE_EVENT',
                    'SUCCESS',
                    'PERFORMANCE_FIXTURE',
                    'performance-' || LPAD(event_number::text, 8, '0'),
                    CURRENT_TIMESTAMP - MAKE_INTERVAL(secs => event_number)
                FROM generate_series(1, :audit_count) event_number
                SQL,
            [
                'user_id' => self::USER_ID,
                'tenant_id' => self::TENANT_ID,
                'audit_count' => self::AUDIT_COUNT,
            ],
        );

        $this->connection->executeStatement('ANALYZE issues');
    }

    /**
     * @param array<string, int|string> $parameters
     *
     * @return array{
     *     indexes: list<string>,
     *     sequential_relations: list<string>,
     *     project_scope_indexed: bool,
     *     execution_ms: float,
     *     planning_ms: float,
     * }
     */
    private function explain(string $sql, array $parameters): array
    {
        $encoded = $this->connection->fetchOne(
            "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)\n" . $sql,
            $parameters,
        );

        if (!is_string($encoded)) {
            self::fail('PostgreSQL did not return an encoded query plan.');
        }

        try {
            $document = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('PostgreSQL returned an invalid JSON query plan: ' . $exception->getMessage());
        }

        if (!is_array($document) || !is_array($document[0] ?? null)) {
            self::fail('PostgreSQL returned an unexpected query plan document.');
        }

        $root = $document[0];
        $plan = $root['Plan'] ?? null;

        if (!is_array($plan)) {
            self::fail('PostgreSQL query plan has no root node.');
        }

        $indexes = [];
        $sequentialRelations = [];
        $projectScopeIndexed = false;
        $this->collectPlanFacts(
            $plan,
            $indexes,
            $sequentialRelations,
            $projectScopeIndexed,
        );

        return [
            'indexes' => array_values(array_unique($indexes)),
            'sequential_relations' => array_values(array_unique($sequentialRelations)),
            'project_scope_indexed' => $projectScopeIndexed,
            'execution_ms' => $this->milliseconds($root, 'Execution Time'),
            'planning_ms' => $this->milliseconds($root, 'Planning Time'),
        ];
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $indexes
     * @param list<string> $sequentialRelations
     */
    private function collectPlanFacts(
        array $node,
        array &$indexes,
        array &$sequentialRelations,
        bool &$projectScopeIndexed,
    ): void {
        $index = $node['Index Name'] ?? null;

        if (is_string($index)) {
            $indexes[] = $index;
        }

        $indexCondition = $node['Index Cond'] ?? null;

        if (
            is_string($indexCondition)
            && str_contains($indexCondition, 'tenant_id')
            && str_contains($indexCondition, 'project_id')
        ) {
            $projectScopeIndexed = true;
        }

        if (($node['Node Type'] ?? null) === 'Seq Scan') {
            $relation = $node['Relation Name'] ?? null;

            if (is_string($relation)) {
                $sequentialRelations[] = $relation;
            }
        }

        $children = $node['Plans'] ?? null;

        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collectPlanFacts(
                    $child,
                    $indexes,
                    $sequentialRelations,
                    $projectScopeIndexed,
                );
            }
        }
    }

    /**
     * @param array<mixed> $root
     */
    private function milliseconds(array $root, string $field): float
    {
        $value = $root[$field] ?? null;

        if (!is_int($value) && !is_float($value)) {
            self::fail(sprintf('PostgreSQL query plan has no numeric %s.', $field));
        }

        return (float) $value;
    }

    /**
     * @param array{
     *     indexes: list<string>,
     *     sequential_relations: list<string>,
     *     project_scope_indexed: bool,
     *     execution_ms: float,
     *     planning_ms: float,
     * } $plan
     */
    private function assertIndexedIssuePlan(array $plan, string $index): void
    {
        $this->assertOneOfIndexedIssuePlans($plan, [$index]);
    }

    /**
     * @param array{
     *     indexes: list<string>,
     *     sequential_relations: list<string>,
     *     project_scope_indexed: bool,
     *     execution_ms: float,
     *     planning_ms: float,
     * } $plan
     * @param non-empty-list<string> $expectedIndexes
     */
    private function assertOneOfIndexedIssuePlans(
        array $plan,
        array $expectedIndexes,
    ): void {
        self::assertNotSame(
            [],
            array_values(array_intersect($expectedIndexes, $plan['indexes'])),
            sprintf(
                'Expected one of %s, used indexes: %s',
                implode(', ', $expectedIndexes),
                implode(', ', $plan['indexes']),
            ),
        );
        self::assertNotContains(
            'issues',
            $plan['sequential_relations'],
            'The performance fixture must not fall back to a sequential issue scan.',
        );
        self::assertLessThan(
            self::SQL_SMOKE_LIMIT_MS,
            $plan['execution_ms'],
            sprintf(
                'The raw SQL smoke exceeded %.0f ms (planning %.3f ms).',
                self::SQL_SMOKE_LIMIT_MS,
                $plan['planning_ms'],
            ),
        );
    }

    /**
     * @param array{
     *     indexes: list<string>,
     *     sequential_relations: list<string>,
     *     project_scope_indexed: bool,
     *     execution_ms: float,
     *     planning_ms: float,
     * } $plan
     */
    private function assertSpecializedOrScopedIssuePlan(
        array $plan,
        string $specializedIndex,
    ): void {
        self::assertTrue(
            in_array($specializedIndex, $plan['indexes'], true)
                || $plan['project_scope_indexed'],
            sprintf(
                'Expected %s or an indexed tenant/project scope, used indexes: %s',
                $specializedIndex,
                implode(', ', $plan['indexes']),
            ),
        );
        self::assertNotContains(
            'issues',
            $plan['sequential_relations'],
            'The performance fixture must not fall back to a sequential issue scan.',
        );
        self::assertLessThan(
            self::SQL_SMOKE_LIMIT_MS,
            $plan['execution_ms'],
            sprintf(
                'The raw SQL smoke exceeded %.0f ms (planning %.3f ms).',
                self::SQL_SMOKE_LIMIT_MS,
                $plan['planning_ms'],
            ),
        );
    }

    private function assertQueryBudget(int $expected, string $operation): void
    {
        self::assertSame(
            $expected,
            $this->queries->count(),
            sprintf(
                '%s exceeded its fixed SQL budget. Statements: %s',
                ucfirst($operation),
                implode(' | ', $this->queries->statements()),
            ),
        );
    }
}
