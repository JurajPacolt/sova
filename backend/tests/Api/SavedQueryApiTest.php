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
 * End-to-end cover for saved queries.
 *
 * The rules worth protecting: only a valid query is stored and its canonical
 * form is the server's, a grant lets somebody run a query but never see an
 * issue they otherwise could not, holding EDIT is not enough to retire somebody
 * else's query, and a name collides only among live queries of one owner.
 */
final class SavedQueryApiTest extends TestCase
{
    private const string PASSWORD = 'A unique saved query passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $ownerMembershipId;
    private string $memberMembershipId;
    private string $outsiderMembershipId;
    private string $tenantId;
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
        $this->ownerId = $this->insertUser('sq-owner');
        $memberId = $this->insertUser('sq-member');
        $outsiderId = $this->insertUser('sq-outsider');
        $this->tenantId = $this->insertTenant('sq-primary');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $this->ownerId,
            DefaultRole::TenantOwner,
        );
        $this->memberMembershipId = $this->addMembership(
            $this->tenantId,
            $memberId,
            DefaultRole::Member,
        );
        $this->outsiderMembershipId = $this->addMembership(
            $this->tenantId,
            $outsiderId,
            DefaultRole::Member,
        );
        $this->projectId = $this->createProject();
        $this->issueTypes = $this->loadIssueTypes();
        $this->grantProjectRole($this->memberMembershipId, DefaultRole::Member);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testSavingStoresTheServerCanonicalFormAndStartsPrivate(): void
    {
        $login = $this->login('sq-owner');

        $response = $this->create($login, 'My open work', 'project = app and priority = high');
        self::assertSame(201, $response->getStatusCode());

        $query = $this->savedQueryOf($response);
        self::assertSame('My open work', $query['name'] ?? null);
        // The client wrote lower case; the stored canonical form is normalised
        // by the server and the raw text is kept beside it.
        self::assertSame('project = APP AND priority = HIGH', $query['canonical_query'] ?? null);
        self::assertSame('project = app and priority = high', $query['raw_query'] ?? null);
        self::assertSame('PRIVATE', $query['visibility'] ?? null);
        self::assertSame('EDIT', $query['viewer_access'] ?? null);
        self::assertTrue($query['viewer_is_owner'] ?? null);
        self::assertSame(1, $query['version'] ?? null);
    }

    public function testOnlyAValidQueryCanBeSaved(): void
    {
        $login = $this->login('sq-owner');

        $response = $this->create($login, 'Broken', 'nope = 1');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('SAVED_QUERY_INVALID', $this->problemCode($response));
        self::assertSame([], $this->rows($this->list($login), 'saved_queries'));
    }

    public function testNameIsUniquePerOwnerButFreedByArchiving(): void
    {
        $login = $this->login('sq-owner');
        $first = $this->createId($login, 'Open work', 'project = APP');

        // Case and spacing must not be enough to make two names look different.
        $duplicate = $this->create($login, '  open   WORK ', 'priority = HIGH');
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame('SAVED_QUERY_NAME_TAKEN', $this->problemCode($duplicate));

        $this->archive($login, $first, 1);

        $reused = $this->create($login, 'Open work', 'priority = HIGH');
        self::assertSame(201, $reused->getStatusCode());
    }

    /**
     * Another member's private query is not merely forbidden — it is invisible,
     * so the endpoint cannot be used to find out that it exists.
     */
    public function testPrivateQueryIsInvisibleToEverybodyElse(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Mine only', 'project = APP');

        $member = $this->login('sq-member');
        self::assertSame([], $this->rows($this->list($member), 'saved_queries'));

        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->queryPath($savedQueryId),
            $member,
        ));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('SAVED_QUERY_NOT_FOUND', $this->problemCode($response));
    }

    public function testGrantingViewMakesTheQueryVisibleWithoutMakingItEditable(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Shared work', 'project = APP');

        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->memberMembershipId, 'access' => 'VIEW'],
        ]);

        $member = $this->login('sq-member');
        $listed = $this->rows($this->list($member), 'saved_queries');
        self::assertCount(1, $listed);
        self::assertSame('VIEW', $listed[0]['viewer_access'] ?? null);
        self::assertFalse($listed[0]['viewer_is_owner'] ?? null);
        // Granting flips the visibility, derived from the grants themselves.
        self::assertSame('SHARED', $listed[0]['visibility'] ?? null);

        $edit = $this->app->handle(
            $this->authenticatedRequest('PATCH', $this->queryPath($savedQueryId), $member)
                ->withParsedBody([
                    'expected_version' => 1,
                    'name' => 'Hijacked',
                    'query' => 'project = APP',
                ]),
        );
        self::assertSame(403, $edit->getStatusCode());
    }

    public function testEditGrantAllowsContentChangesButNotArchiving(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Team work', 'project = APP');
        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->memberMembershipId, 'access' => 'EDIT'],
        ]);

        $member = $this->login('sq-member');
        $edited = $this->app->handle(
            $this->authenticatedRequest('PATCH', $this->queryPath($savedQueryId), $member)
                ->withParsedBody([
                    'expected_version' => 1,
                    'name' => 'Team work refined',
                    'query' => 'project = APP AND priority = HIGH',
                ]),
        );
        self::assertSame(200, $edited->getStatusCode());
        self::assertSame(
            'project = APP AND priority = HIGH',
            $this->savedQueryOf($edited)['canonical_query'] ?? null,
        );

        // Retiring somebody else's query is the owner's call, not an editor's.
        $archived = $this->app->handle(
            $this->authenticatedRequest('POST', $this->queryPath($savedQueryId) . '/archive', $member)
                ->withParsedBody(['expected_version' => 2]),
        );
        self::assertSame(403, $archived->getStatusCode());
    }

    /**
     * A grant conveys the query, never the issues. The reader still only sees
     * what their own `issue.view` scope allows.
     */
    public function testSharingDoesNotConveyAccessToTheIssues(): void
    {
        $owner = $this->login('sq-owner');
        $issueId = $this->createIssueId($owner, 'BUG', 'Secret work');
        self::assertNotSame('', $issueId);
        $savedQueryId = $this->createId($owner, 'Everything', 'project = APP');

        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->outsiderMembershipId, 'access' => 'VIEW'],
        ]);

        // The owner runs the query and finds their issue.
        $found = $this->rows($this->search($owner, 'project = APP'), 'issues');
        self::assertCount(1, $found);
        self::assertSame($issueId, $found[0]['id'] ?? null);

        // The outsider is a tenant member with no role in the project.
        $outsider = $this->login('sq-outsider');
        self::assertCount(1, $this->rows($this->list($outsider), 'saved_queries'));

        $search = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/search', $this->tenantId),
                $outsider,
            )->withParsedBody(['query' => 'project = APP']),
        );

        // Running the very query they were granted still reaches nothing: the
        // project code does not resolve inside their own scope, and it answers
        // exactly as a misspelt code would, so the query cannot be used to
        // discover that the project exists.
        self::assertSame(422, $search->getStatusCode());
        self::assertSame('QUERY_INVALID', $this->problemCode($search));
    }

    public function testGrantMustNameAnActivePrincipalOfThisTenant(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Shared', 'project = APP');

        $unknown = $this->app->handle(
            $this->authenticatedRequest('PUT', $this->queryPath($savedQueryId) . '/grants', $owner)
                ->withParsedBody([
                    'grants' => [
                        ['membership_id' => (string) UuidV7::generate(), 'access' => 'VIEW'],
                    ],
                ]),
        );
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame('SAVED_QUERY_GRANT_INVALID', $this->problemCode($unknown));

        // Exactly one principal per grant: never both, never neither.
        $both = $this->app->handle(
            $this->authenticatedRequest('PUT', $this->queryPath($savedQueryId) . '/grants', $owner)
                ->withParsedBody([
                    'grants' => [
                        [
                            'membership_id' => $this->memberMembershipId,
                            'workgroup_id' => (string) UuidV7::generate(),
                            'access' => 'VIEW',
                        ],
                    ],
                ]),
        );
        self::assertSame(422, $both->getStatusCode());
    }

    public function testReplacingGrantsRemovesTheOnesLeftOut(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Shared', 'project = APP');

        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->memberMembershipId, 'access' => 'EDIT'],
            ['membership_id' => $this->outsiderMembershipId, 'access' => 'VIEW'],
        ]);
        self::assertCount(2, $this->rows($this->grants($owner, $savedQueryId), 'grants'));

        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->memberMembershipId, 'access' => 'EDIT'],
        ]);
        self::assertCount(1, $this->rows($this->grants($owner, $savedQueryId), 'grants'));

        $outsider = $this->login('sq-outsider');
        self::assertSame([], $this->rows($this->list($outsider), 'saved_queries'));

        // Removing every grant makes the query private again.
        $this->putGrants($owner, $savedQueryId, []);
        $listed = $this->rows($this->list($owner), 'saved_queries');
        self::assertSame('PRIVATE', $listed[0]['visibility'] ?? null);
    }

    public function testSharingAndArchivingLandInTheSecurityLog(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Audited', 'project = APP');

        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->memberMembershipId, 'access' => 'VIEW'],
        ]);
        $this->putGrants($owner, $savedQueryId, []);
        $this->archive($owner, $savedQueryId, 1);

        $events = $this->auditEvents($savedQueryId);

        self::assertSame(
            ['SAVED_QUERY_SHARED', 'SAVED_QUERY_SHARED', 'SAVED_QUERY_ARCHIVED'],
            array_column($events, 'event_type'),
        );
        // Removing every grant is still a sharing decision, but the reason says
        // which way it went.
        self::assertSame(
            ['SAVED_QUERY_SHARED', 'SAVED_QUERY_UNSHARED', 'SAVED_QUERY_ARCHIVED'],
            array_column($events, 'reason_code'),
        );

        foreach ($events as $event) {
            $metadata = $event['metadata'];
            self::assertIsString($metadata);

            // The log is read with `tenant.audit.view`, which is not the right
            // to read somebody's private query: no name and no query text.
            self::assertStringNotContainsString('Audited', $metadata);
            self::assertStringNotContainsString('project = APP', $metadata);
        }
    }

    public function testStaleVersionIsReported(): void
    {
        $login = $this->login('sq-owner');
        $savedQueryId = $this->createId($login, 'Work', 'project = APP');

        $response = $this->app->handle(
            $this->authenticatedRequest('PATCH', $this->queryPath($savedQueryId), $login)
                ->withParsedBody([
                    'expected_version' => 7,
                    'name' => 'Work',
                    'query' => 'project = APP',
                ]),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SAVED_QUERY_VERSION_CONFLICT', $this->problemCode($response));
    }

    public function testFavouriteIsPersonalAndIdempotent(): void
    {
        $owner = $this->login('sq-owner');
        $savedQueryId = $this->createId($owner, 'Shared', 'project = APP');
        $this->putGrants($owner, $savedQueryId, [
            ['membership_id' => $this->memberMembershipId, 'access' => 'VIEW'],
        ]);

        foreach (['PUT', 'PUT'] as $method) {
            $response = $this->app->handle($this->authenticatedRequest(
                $method,
                $this->queryPath($savedQueryId) . '/favourite',
                $owner,
            ));
            self::assertSame(200, $response->getStatusCode());
        }

        self::assertTrue($this->rows($this->list($owner), 'saved_queries')[0]['favourite'] ?? null);

        // It is the caller's bookmark, so nobody else's list changes.
        $member = $this->login('sq-member');
        self::assertFalse($this->rows($this->list($member), 'saved_queries')[0]['favourite'] ?? null);

        $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->queryPath($savedQueryId) . '/favourite',
            $owner,
        ));
        self::assertFalse($this->rows($this->list($owner), 'saved_queries')[0]['favourite'] ?? null);
    }

    /**
     * `saved-query.manage` lets an administrator take over an abandoned shared
     * query without granting them the issues behind it.
     */
    public function testAdministratorSeesEverySharedQuery(): void
    {
        $member = $this->login('sq-member');
        $savedQueryId = $this->createId($member, 'Member work', 'project = APP');
        $this->putGrants($member, $savedQueryId, [
            ['membership_id' => $this->outsiderMembershipId, 'access' => 'VIEW'],
        ]);

        // The tenant owner holds saved-query.manage but no grant of their own.
        $owner = $this->login('sq-owner');
        $listed = $this->rows($this->list($owner), 'saved_queries');
        self::assertCount(1, $listed);
        self::assertSame('EDIT', $listed[0]['viewer_access'] ?? null);
        self::assertFalse($listed[0]['viewer_is_owner'] ?? null);
    }

    /**
     * @param list<array<string, string>> $grants
     */
    private function putGrants(
        ResponseInterface $login,
        string $savedQueryId,
        array $grants,
    ): void {
        $response = $this->app->handle(
            $this->authenticatedRequest('PUT', $this->queryPath($savedQueryId) . '/grants', $login)
                ->withParsedBody(['grants' => $grants]),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function search(ResponseInterface $login, string $query): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/issues/search', $this->tenantId),
                $login,
            )->withParsedBody(['query' => $query]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function grants(ResponseInterface $login, string $savedQueryId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->queryPath($savedQueryId) . '/grants',
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditEvents(string $savedQueryId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT event_type, reason_code, metadata
                FROM security_audit_events
                WHERE tenant_id = :tenant_id
                    AND metadata::text LIKE :needle
                ORDER BY occurred_at, id
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'needle' => '%' . $savedQueryId . '%',
            ],
        );

        return $rows;
    }

    private function archive(ResponseInterface $login, string $savedQueryId, int $version): void
    {
        $response = $this->app->handle(
            $this->authenticatedRequest('POST', $this->queryPath($savedQueryId) . '/archive', $login)
                ->withParsedBody(['expected_version' => $version]),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    private function create(
        ResponseInterface $login,
        string $name,
        string $query,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest('POST', $this->collectionPath(), $login)
                ->withParsedBody(['name' => $name, 'query' => $query]),
        );
    }

    private function createId(ResponseInterface $login, string $name, string $query): string
    {
        $response = $this->create($login, $name, $query);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->savedQueryOf($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function list(ResponseInterface $login): array
    {
        $response = $this->app->handle(
            $this->authenticatedRequest('GET', $this->collectionPath(), $login),
        );
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedQueryOf(ResponseInterface $response): array
    {
        $payload = $this->decode($response);
        $query = $payload['saved_query'] ?? null;
        self::assertIsArray($query);

        $result = [];

        foreach ($query as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function collectionPath(): string
    {
        return sprintf('/api/v1/tenants/%s/saved-queries', $this->tenantId);
    }

    private function queryPath(string $savedQueryId): string
    {
        return sprintf('%s/%s', $this->collectionPath(), $savedQueryId);
    }

    private function problemCode(ResponseInterface $response): string
    {
        $code = $this->decode($response)['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $key): array
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

    private function grantProjectRole(string $membershipId, DefaultRole $role): void
    {
        $roleId = $this->connection->fetchOne(
            'SELECT id FROM project_roles WHERE project_id = ? AND code = ?',
            [$this->projectId, $role->value],
        );
        self::assertIsString($roleId);

        $response = $this->app->handle($this->authenticatedRequest(
            'PUT',
            sprintf(
                '/api/v1/tenants/%s/projects/%s/members/%s/roles/%s',
                $this->tenantId,
                $this->projectId,
                $membershipId,
                $roleId,
            ),
            $this->login('sq-owner'),
        ));
        self::assertSame(204, $response->getStatusCode());
    }

    private function createIssueId(
        ResponseInterface $login,
        string $typeCode,
        string $title,
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
                $this->login('sq-owner'),
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
