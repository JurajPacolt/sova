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
 * End-to-end cover for SovaQL execution: the whitelist compiler, the
 * non-removable tenant/project/`issue.view` scope, cursor pagination and the
 * safety limits.
 *
 * The negative cases matter most here. A member without a project role must see
 * nothing rather than be told "forbidden", a code from a project they cannot
 * reach must be indistinguishable from a code that does not exist, and a cursor
 * must stop working the moment the query or the sort changes.
 */
final class IssueSearchApiTest extends TestCase
{
    private const string PASSWORD = 'A unique issue search passphrase';

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
        $this->ownerId = $this->insertUser('search-owner');
        $this->outsiderId = $this->insertUser('search-outsider');
        $this->tenantId = $this->insertTenant('search-primary');
        $this->foreignTenantId = $this->insertTenant('search-foreign');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $roles->provisionDefaults($this->foreignTenantId, $this->ownerId);
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
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testEmptyQueryReturnsEveryIssueInTheAuthorisedScope(): void
    {
        $login = $this->login('search-owner');
        $this->createIssueId($login, 'BUG', 'Login times out');
        $this->createIssueId($login, 'TASK', 'Rotate the signing key');

        $payload = $this->search($login, ['query' => '']);

        self::assertCount(2, $this->issues($payload));
        self::assertSame('', $payload['canonical_query'] ?? null);
        self::assertArrayHasKey('next_cursor', $payload);
        self::assertNull($payload['next_cursor']);
    }

    public function testFiltersCompileToTheExpectedRows(): void
    {
        $login = $this->login('search-owner');
        $this->createIssueId($login, 'BUG', 'Login times out');
        $this->createIssueId($login, 'TASK', 'Rotate the signing key');
        $this->createIssueId($login, 'STORY', 'Publish the audit export');

        self::assertSame(
            ['Login times out'],
            $this->titles($login, 'type = BUG'),
        );
        self::assertSame(
            ['Login times out', 'Rotate the signing key'],
            $this->titles($login, 'type IN (BUG, TASK) ORDER BY key ASC'),
        );
        self::assertSame(
            ['Publish the audit export', 'Rotate the signing key'],
            $this->titles($login, 'type != BUG ORDER BY title ASC'),
        );
        self::assertSame(
            ['Login times out', 'Publish the audit export', 'Rotate the signing key'],
            $this->titles($login, 'project = APP ORDER BY title ASC'),
        );
        self::assertSame([], $this->titles($login, 'statusCategory = DONE'));
        self::assertSame(
            ['Login times out', 'Publish the audit export', 'Rotate the signing key'],
            $this->titles($login, 'statusCategory != DONE ORDER BY title ASC'),
        );
    }

    public function testFulltextAndTitleSearchAreSafeAndLiteral(): void
    {
        $login = $this->login('search-owner');
        $this->createIssue($login, [
            'issue_type_id' => $this->issueTypes['BUG'],
            'title' => 'Login times out',
            'description' => 'The session expires while the reset form is open.',
        ]);
        $this->createIssueId($login, 'TASK', '100% of the quota_used');

        self::assertSame(
            ['Login times out'],
            $this->titles($login, 'text ~ "reset form"'),
        );
        self::assertSame([], $this->titles($login, 'text ~ "nonexistent phrase"'));

        // `%` and `_` inside the value must match literally, not as wildcards:
        // the exact text matches, `_` refuses to stand for any character, and a
        // bare `%` matches only because that row really contains a per-cent sign.
        self::assertSame(
            ['100% of the quota_used'],
            $this->titles($login, 'title ~ "100% of the quota_used"'),
        );
        self::assertSame([], $this->titles($login, 'title ~ "100% of the quotaXused"'));
        self::assertSame(
            ['100% of the quota_used'],
            $this->titles($login, 'title ~ "%"'),
        );
        // A wildcard would make this match "Login times out"; a literal `%` cannot.
        self::assertSame([], $this->titles($login, 'title ~ "Login%out"'));
    }

    public function testAssigneeAndCurrentUserResolveToMemberships(): void
    {
        $login = $this->login('search-owner');
        $issueId = $this->createIssueId($login, 'BUG', 'Assigned to the owner');
        $this->createIssueId($login, 'TASK', 'Nobody owns this');
        $this->connection->update(
            'issues',
            ['assignee_membership_id' => $this->ownerMembershipId],
            ['id' => $issueId],
        );

        self::assertSame(
            ['Assigned to the owner'],
            $this->titles($login, 'assignee = currentUser()'),
        );
        self::assertSame(
            ['Assigned to the owner'],
            $this->titles($login, sprintf('assignee = user("%s")', $this->ownerMembershipId)),
        );
        self::assertSame(
            ['Nobody owns this'],
            $this->titles($login, 'assignee IS EMPTY'),
        );
        self::assertSame(
            ['Assigned to the owner'],
            $this->titles($login, 'assignee IS NOT EMPTY'),
        );
    }

    /**
     * `ORDER BY priority DESC` has to mean "most severe first", not the
     * alphabetical order of the stored strings.
     */
    public function testPriorityIsSortedBySeverityNotAlphabetically(): void
    {
        $login = $this->login('search-owner');

        foreach (['LOW', 'CRITICAL', 'NORMAL', 'HIGH'] as $priority) {
            $this->createIssue($login, [
                'issue_type_id' => $this->issueTypes['TASK'],
                'title' => sprintf('Priority %s', $priority),
                'priority' => $priority,
            ]);
        }

        self::assertSame(
            ['Priority CRITICAL', 'Priority HIGH', 'Priority NORMAL', 'Priority LOW'],
            $this->titles($login, 'ORDER BY priority DESC'),
        );
    }

    public function testCursorPaginationWalksEveryRowExactlyOnce(): void
    {
        $login = $this->login('search-owner');

        for ($index = 1; $index <= 5; $index++) {
            $this->createIssueId($login, 'TASK', sprintf('Paged issue %d', $index));
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $payload = $this->search($login, [
                'query' => 'ORDER BY key ASC',
                'page_size' => 2,
                'cursor' => $cursor,
            ]);

            foreach ($this->issues($payload) as $issue) {
                self::assertIsArray($issue);
                $seen[] = $issue['title'] ?? null;
            }

            $cursor = $payload['next_cursor'] ?? null;
            self::assertLessThan(6, ++$pages, 'Pagination did not terminate.');
        } while (is_string($cursor));

        self::assertSame(
            [
                'Paged issue 1',
                'Paged issue 2',
                'Paged issue 3',
                'Paged issue 4',
                'Paged issue 5',
            ],
            $seen,
        );
    }

    public function testCursorIsRejectedForADifferentQueryOrSort(): void
    {
        $login = $this->login('search-owner');
        $this->createIssueId($login, 'TASK', 'First');
        $this->createIssueId($login, 'TASK', 'Second');

        $first = $this->search($login, ['query' => 'ORDER BY key ASC', 'page_size' => 1]);
        $cursor = $first['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $reused = $this->searchResponse($login, [
            'query' => 'type = TASK ORDER BY key ASC',
            'page_size' => 1,
            'cursor' => $cursor,
        ]);
        self::assertSame(422, $reused->getStatusCode());
        self::assertSame('QUERY_CURSOR_INVALID', $this->problemCode($reused));

        $resorted = $this->searchResponse($login, [
            'query' => 'ORDER BY key DESC',
            'page_size' => 1,
            'cursor' => $cursor,
        ]);
        self::assertSame(422, $resorted->getStatusCode());
        self::assertSame('QUERY_CURSOR_INVALID', $this->problemCode($resorted));

        $forged = $this->searchResponse($login, [
            'query' => 'ORDER BY key ASC',
            'page_size' => 1,
            'cursor' => $cursor . 'x',
        ]);
        self::assertSame(422, $forged->getStatusCode());
        self::assertSame('QUERY_CURSOR_INVALID', $this->problemCode($forged));
    }

    /**
     * A tenant member with no project role holds no `issue.view` anywhere, so
     * the authorised scope is empty and the search simply finds nothing.
     */
    public function testMemberWithoutAProjectRoleSeesNothing(): void
    {
        $owner = $this->login('search-owner');
        $this->createIssueId($owner, 'BUG', 'Only the project team sees this');

        $outsider = $this->login('search-outsider');
        $payload = $this->search($outsider, ['query' => '']);

        self::assertSame([], $this->issues($payload));
    }

    /**
     * The same generic error for a code that does not exist and for one that
     * exists in a project the caller cannot reach — otherwise a query would
     * enumerate foreign configuration.
     */
    public function testUnreachableReferencesAreIndistinguishableFromMissingOnes(): void
    {
        $owner = $this->login('search-owner');
        $this->createIssueId($owner, 'BUG', 'Visible');

        $unknown = $this->searchResponse($owner, ['query' => 'project = NOPE']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame('QUERY_INVALID', $this->problemCode($unknown));

        $outsider = $this->login('search-outsider');
        $unreachable = $this->searchResponse($outsider, ['query' => 'project = APP']);
        self::assertSame(422, $unreachable->getStatusCode());
        self::assertSame('QUERY_INVALID', $this->problemCode($unreachable));

        $missingForOutsider = $this->searchResponse($outsider, ['query' => 'project = NOPE']);
        self::assertSame(422, $missingForOutsider->getStatusCode());
        self::assertSame('QUERY_INVALID', $this->problemCode($missingForOutsider));
    }

    public function testForeignTenantRouteIsRejected(): void
    {
        $login = $this->login('search-owner');
        $this->createIssueId($login, 'BUG', 'Primary tenant only');

        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/search', $this->foreignTenantId),
                $login,
            )->withParsedBody(['query' => '']),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testValidationReportsStructuredErrorsWithoutRunningTheQuery(): void
    {
        $login = $this->login('search-owner');

        $valid = $this->validate($login, 'type = BUG ORDER BY updated DESC');
        self::assertTrue($valid['valid'] ?? null);
        self::assertSame('type = BUG ORDER BY updated DESC', $valid['canonical_query'] ?? null);
        self::assertSame([], $valid['errors'] ?? null);

        $syntax = $this->validate($login, 'type = ');
        self::assertFalse($syntax['valid'] ?? null);
        $errors = $syntax['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        self::assertIsArray($errors[0]);
        self::assertSame('QUERY_SYNTAX_INVALID', $errors[0]['code'] ?? null);
        self::assertArrayHasKey('start', $errors[0]);
        self::assertArrayHasKey('end', $errors[0]);

        $unreachable = $this->validate($login, 'project = NOPE');
        self::assertFalse($unreachable['valid'] ?? null);
        $referenceErrors = $unreachable['errors'] ?? null;
        self::assertIsArray($referenceErrors);
        self::assertIsArray($referenceErrors[0]);
        self::assertSame('QUERY_VALUE_NOT_AVAILABLE', $referenceErrors[0]['code'] ?? null);
    }

    public function testMetadataDescribesFieldsAndActiveLimits(): void
    {
        $login = $this->login('search-owner');
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/issue-query/metadata', $this->tenantId),
            $login,
        ));

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);

        $fields = $payload['fields'] ?? null;
        self::assertIsArray($fields);
        $names = [];

        foreach ($fields as $field) {
            self::assertIsArray($field);
            $names[] = $field['name'] ?? null;
        }

        self::assertContains('project', $names);
        self::assertContains('statusCategory', $names);
        // Fields whose storage arrives in a later phase must not be advertised.
        self::assertNotContains('labels', $names);
        self::assertNotContains('due', $names);

        $limits = $payload['limits'] ?? null;
        self::assertIsArray($limits);
        self::assertSame(8192, $limits['max_query_bytes'] ?? null);
        self::assertSame(50, $limits['default_page_size'] ?? null);
        self::assertSame(100, $limits['max_page_size'] ?? null);
    }

    public function testPageSizeIsCappedAtTheConfiguredMaximum(): void
    {
        $login = $this->login('search-owner');
        $this->createIssueId($login, 'TASK', 'Only one');

        $payload = $this->search($login, ['query' => '', 'page_size' => 5000]);

        self::assertSame(100, $payload['page_size'] ?? null);
    }

    public function testOverlongQueryIsRejectedBeforeExecution(): void
    {
        $login = $this->login('search-owner');
        $response = $this->searchResponse($login, [
            'query' => 'title ~ "' . str_repeat('a', 9000) . '"',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('QUERY_TOO_LONG', $this->problemCode($response));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function search(ResponseInterface $login, array $payload): array
    {
        $response = $this->searchResponse($login, $payload);
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function searchResponse(
        ResponseInterface $login,
        array $payload,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/search', $this->tenantId),
                $login,
            )->withParsedBody($payload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(ResponseInterface $login, string $query): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issue-query/validate', $this->tenantId),
                $login,
            )->withParsedBody(['query' => $query]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return list<string>
     */
    private function titles(ResponseInterface $login, string $query): array
    {
        $titles = [];

        foreach ($this->issues($this->search($login, ['query' => $query])) as $issue) {
            self::assertIsArray($issue);
            $title = $issue['title'] ?? null;
            self::assertIsString($title);
            $titles[] = $title;
        }

        return $titles;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<mixed>
     */
    private function issues(array $payload): array
    {
        $issues = $payload['issues'] ?? null;
        self::assertIsArray($issues);

        return array_values($issues);
    }

    private function problemCode(ResponseInterface $response): string
    {
        $payload = $this->decode($response);
        $code = $payload['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createIssue(
        ResponseInterface $login,
        array $payload,
    ): ResponseInterface {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf(
                    '/api/v1/tenants/%s/projects/%s/issues',
                    $this->tenantId,
                    $this->projectId,
                ),
                $login,
            )->withParsedBody($payload),
        );
        self::assertSame(201, $response->getStatusCode());

        return $response;
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
        $payload = $this->decode($response);
        $issue = $payload['issue'] ?? null;
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
                $this->login('search-owner'),
            )->withParsedBody([
                'code' => $code,
                'name' => sprintf('Project %s', $code),
                'lead_membership_id' => $this->ownerMembershipId,
            ]),
        );
        self::assertSame(201, $response->getStatusCode());
        $payload = $this->decode($response);
        $project = $payload['project'] ?? null;
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
