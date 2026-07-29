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

/**
 * End-to-end cover for what a widget actually shows.
 *
 * The rule this file exists for: **a widget is only a pointer.** It names a
 * saved query and how to summarise it, and the query runs as whoever is
 * looking — so the same widget legitimately shows different numbers to
 * different people, and a count never includes an issue the reader could not
 * open. Aggregating before applying that scope would disclose the existence of
 * those issues just as surely as returning them.
 */
final class WidgetDataApiTest extends TestCase
{
    private const string PASSWORD = 'A unique widget data passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $tenantId;
    private string $projectId;
    private string $ownerMembershipId;
    private string $memberMembershipId;

    /**
     * @var array<string, string>
     */
    private array $issueTypes = [];

    /** Names are unique per owner, so each scratch board needs its own. */
    private int $scratchCounter = 0;

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
        $ownerId = $this->insertUser('data-owner');
        $memberId = $this->insertUser('data-member');
        $this->tenantId = $this->insertTenant('data-primary');
        $roles->provisionDefaults($this->tenantId, $ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $ownerId,
            DefaultRole::TenantOwner,
        );
        $this->memberMembershipId = $this->addMembership(
            $this->tenantId,
            $memberId,
            DefaultRole::Member,
        );
        $this->projectId = $this->createProject();
        $this->issueTypes = $this->loadIssueTypes();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testCountWidgetReportsTheNumberOfMatchingIssues(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'First');
        $this->createIssueId($login, 'BUG', 'Second');
        $this->createIssueId($login, 'TASK', 'Third');

        $data = $this->widgetData($login, 'issue_count', 'project = APP', []);

        self::assertSame(3, $data['count'] ?? null);
    }

    /**
     * The heart of the matter: the very same widget, seen by somebody whose
     * `issue.view` reaches nothing, counts nothing. The scope is applied before
     * the aggregation, not to its result.
     */
    public function testTheSameWidgetCountsOnlyWhatTheReaderMaySee(): void
    {
        $owner = $this->login('data-owner');
        $this->createIssueId($owner, 'BUG', 'Secret work');

        $dashboardId = $this->createDashboard($owner, 'Shared board');
        $savedQueryId = $this->createSavedQuery($owner, 'Everything', 'statusCategory != DONE');
        $this->shareSavedQuery($owner, $savedQueryId, $this->memberMembershipId);
        $widgetId = $this->addWidget($owner, $dashboardId, $savedQueryId, 'issue_count', []);

        self::assertSame(1, $this->loadWidget($owner, $dashboardId, $widgetId)['count'] ?? null);

        // The member holds a grant on the query but no role in the project, so
        // the widget on their own dashboard reaches nothing.
        $member = $this->login('data-member');
        $memberDashboard = $this->createDashboard($member, 'My board');
        $memberWidget = $this->addWidget(
            $member,
            $memberDashboard,
            $savedQueryId,
            'issue_count',
            [],
        );

        self::assertSame(
            0,
            $this->loadWidget($member, $memberDashboard, $memberWidget)['count'] ?? null,
        );
    }

    public function testBreakdownGroupsByTheConfiguredDimension(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'One', 'HIGH');
        $this->createIssueId($login, 'BUG', 'Two', 'HIGH');
        $this->createIssueId($login, 'TASK', 'Three', 'LOW');

        $data = $this->widgetData($login, 'issue_breakdown', 'project = APP', [
            'group_by' => 'priority',
            'sort' => 'COUNT',
        ]);

        $buckets = $this->entries($data, 'buckets');
        self::assertCount(2, $buckets);
        // Ordered by count, so the largest group leads.
        self::assertSame('HIGH', $buckets[0]['label'] ?? null);
        self::assertSame(2, $buckets[0]['count'] ?? null);
        self::assertSame('LOW', $buckets[1]['label'] ?? null);
        self::assertSame(1, $buckets[1]['count'] ?? null);
    }

    /**
     * A chart that silently drops unassigned issues adds up to less than the
     * total and quietly misleads, so the empty bucket is reported.
     */
    public function testBreakdownReportsTheEmptyBucketUnlessAskedNotTo(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'Unassigned');

        $withEmpty = $this->widgetData($login, 'issue_breakdown', 'project = APP', [
            'group_by' => 'assignee',
            'include_empty' => true,
        ]);
        $buckets = $this->entries($withEmpty, 'buckets');
        self::assertCount(1, $buckets);
        // `??` would collapse the null we are asserting, so the key is
        // checked for presence first.
        self::assertArrayHasKey('key', $buckets[0]);
        self::assertNull($buckets[0]['key']);
        self::assertSame(1, $buckets[0]['count'] ?? null);

        $withoutEmpty = $this->widgetData($login, 'issue_breakdown', 'project = APP', [
            'group_by' => 'assignee',
            'include_empty' => false,
        ]);
        self::assertSame([], $this->entries($withoutEmpty, 'buckets'));
    }

    public function testMatrixCountsBothAxesTogether(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'One', 'HIGH');
        $this->createIssueId($login, 'BUG', 'Two', 'HIGH');
        $this->createIssueId($login, 'TASK', 'Three', 'LOW');

        $data = $this->widgetData($login, 'issue_matrix', 'project = APP', [
            'rows' => 'type',
            'columns' => 'priority',
        ]);

        $cells = $this->entries($data, 'cells');
        self::assertCount(2, $cells);
        self::assertSame(2, $cells[0]['count'] ?? null);
        self::assertSame('HIGH', $cells[0]['column_label'] ?? null);
    }

    public function testListWidgetReturnsTheFirstPageInTheQuerysOwnOrder(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'Alpha');
        $this->createIssueId($login, 'BUG', 'Beta');
        $this->createIssueId($login, 'BUG', 'Gamma');

        $data = $this->widgetData(
            $login,
            'issue_list',
            'project = APP ORDER BY created ASC',
            ['limit' => 5, 'columns' => ['title', 'status', 'priority']],
        );

        $issues = $this->entries($data, 'issues');
        self::assertCount(3, $issues);
        // The saved query's ORDER BY decides; the widget does not re-sort.
        self::assertSame('Alpha', $issues[0]['title'] ?? null);
        self::assertSame('Gamma', $issues[2]['title'] ?? null);
    }

    public function testTimeSeriesFillsEmptyBucketsWithZero(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'Today');

        $data = $this->widgetData($login, 'issue_time_series', 'project = APP', [
            'event' => 'CREATED',
            'bucket' => 'DAY',
            'range_days' => 7,
        ]);

        $series = $this->entries($data, 'series');
        self::assertCount(1, $series);
        self::assertSame('CREATED', $series[0]['event'] ?? null);

        $points = $series[0]['points'] ?? null;
        self::assertIsArray($points);
        // Eight buckets for a seven-day window, including today. A gap reads as
        // zero rather than as a missing point a chart would interpolate across.
        self::assertCount(8, $points);
        $counts = array_map(
            static fn(mixed $point): mixed => is_array($point) ? ($point['count'] ?? null) : null,
            $points,
        );
        self::assertSame(1, $counts[7] ?? null);
        self::assertSame(0, $counts[0] ?? null);
    }

    public function testComparingCreatedAndResolvedReturnsTwoSeries(): void
    {
        $login = $this->login('data-owner');
        $this->createIssueId($login, 'BUG', 'One');

        $data = $this->widgetData($login, 'issue_time_series', 'project = APP', [
            'compare_created_resolved' => true,
            'bucket' => 'DAY',
            'range_days' => 7,
        ]);

        $series = $this->entries($data, 'series');
        self::assertCount(2, $series);
        self::assertSame('CREATED', $series[0]['event'] ?? null);
        self::assertSame('RESOLVED', $series[1]['event'] ?? null);
    }

    /**
     * The source is re-read on every load: a grant can be withdrawn or the
     * query archived between two refreshes, and the widget must find that out
     * rather than keep showing what it cached.
     */
    public function testWidgetReportsWhenItsSourceIsGone(): void
    {
        $owner = $this->login('data-owner');
        $dashboardId = $this->createDashboard($owner, 'Shared board');
        $savedQueryId = $this->createSavedQuery($owner, 'Everything', 'statusCategory != DONE');
        $this->shareSavedQuery($owner, $savedQueryId, $this->memberMembershipId);

        $member = $this->login('data-member');
        $memberDashboard = $this->createDashboard($member, 'My board');
        $memberWidget = $this->addWidget(
            $member,
            $memberDashboard,
            $savedQueryId,
            'issue_count',
            [],
        );
        self::assertIsInt(
            $this->loadWidget($member, $memberDashboard, $memberWidget)['count'] ?? null,
        );

        // The owner withdraws the grant.
        $revoked = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/saved-queries/%s/grants',
                    $this->tenantId,
                    $savedQueryId,
                ),
                $owner,
            )->withParsedBody(['grants' => []]),
        );
        self::assertSame(200, $revoked->getStatusCode());

        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets/%s/data',
                $this->tenantId,
                $memberDashboard,
                $memberWidget,
            ),
            $this->login('data-member'),
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('WIDGET_DATA_SOURCE_NOT_FOUND', $this->problemCode($response));

        // And the dashboard itself still loads: one unreachable source must not
        // blank the whole page.
        $widgets = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets',
                $this->tenantId,
                $memberDashboard,
            ),
            $this->login('data-member'),
        ));
        self::assertSame(200, $widgets->getStatusCode());
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    private function widgetData(
        ResponseInterface $login,
        string $typeKey,
        string $query,
        array $configuration,
    ): array {
        $this->scratchCounter++;
        $dashboardId = $this->createDashboard(
            $login,
            sprintf('Board %s %d', $typeKey, $this->scratchCounter),
        );
        $savedQueryId = $this->createSavedQuery(
            $login,
            sprintf('Query %s %d', $typeKey, $this->scratchCounter),
            $query,
        );
        $widgetId = $this->addWidget($login, $dashboardId, $savedQueryId, $typeKey, $configuration);

        return $this->loadWidget($login, $dashboardId, $widgetId);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadWidget(
        ResponseInterface $login,
        string $dashboardId,
        string $widgetId,
    ): array {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets/%s/data',
                $this->tenantId,
                $dashboardId,
                $widgetId,
            ),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $result = [];

        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? null;
        self::assertIsArray($rows);

        $result = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $entry = [];

            foreach ($row as $column => $value) {
                $entry[(string) $column] = $value;
            }

            $result[] = $entry;
        }

        return $result;
    }

    private function createDashboard(ResponseInterface $login, string $name): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/dashboards', $this->tenantId),
                $login,
            )->withParsedBody(['name' => $name]),
        );
        self::assertSame(201, $response->getStatusCode());
        $dashboard = $this->decode($response)['dashboard'] ?? null;
        self::assertIsArray($dashboard);
        $id = $dashboard['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createSavedQuery(
        ResponseInterface $login,
        string $name,
        string $query,
    ): string {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/saved-queries', $this->tenantId),
                $login,
            )->withParsedBody(['name' => $name, 'query' => $query]),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $savedQuery = $this->decode($response)['saved_query'] ?? null;
        self::assertIsArray($savedQuery);
        $id = $savedQuery['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function shareSavedQuery(
        ResponseInterface $login,
        string $savedQueryId,
        string $membershipId,
    ): void {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/saved-queries/%s/grants',
                    $this->tenantId,
                    $savedQueryId,
                ),
                $login,
            )->withParsedBody([
                'grants' => [['membership_id' => $membershipId, 'access' => 'VIEW']],
            ]),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function addWidget(
        ResponseInterface $login,
        string $dashboardId,
        string $savedQueryId,
        string $typeKey,
        array $configuration,
    ): string {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/dashboards/%s/widgets',
                    $this->tenantId,
                    $dashboardId,
                ),
                $login,
            )->withParsedBody([
                'saved_query_id' => $savedQueryId,
                'type_key' => $typeKey,
                'configuration' => $configuration,
            ]),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $widget = $this->decode($response)['widget'] ?? null;
        self::assertIsArray($widget);
        $id = $widget['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function problemCode(ResponseInterface $response): string
    {
        $code = $this->decode($response)['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    private function createIssueId(
        ResponseInterface $login,
        string $typeCode,
        string $title,
        string $priority = 'NORMAL',
    ): string {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/issues',
                    $this->tenantId,
                    $this->projectId,
                ),
                $login,
            )->withParsedBody([
                'issue_type_id' => $this->issueTypes[$typeCode],
                'title' => $title,
                'priority' => $priority,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        $issue = $this->decode($response)['issue'] ?? null;
        self::assertIsArray($issue);
        $id = $issue['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createProject(string $code = 'APP'): string
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/projects', $this->tenantId),
                $this->login('data-owner'),
            )->withParsedBody([
                'code' => $code,
                'name' => sprintf('Project %s', $code),
                'lead_membership_id' => $this->ownerMembershipId,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        $project = $this->decode($response)['project'] ?? null;
        self::assertIsArray($project);
        $id = $project['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function loadIssueTypes(): array
    {
        $types = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT code, id FROM project_issue_types WHERE project_id = ?',
            [$this->projectId],
        ) as $row) {
            $code = $row['code'] ?? null;
            $id = $row['id'] ?? null;

            if (is_string($code) && is_string($id)) {
                $types[$code] = $id;
            }
        }

        return $types;
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

    private function cookieValue(ResponseInterface $response, string $name): string
    {
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
