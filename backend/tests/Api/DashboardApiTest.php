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
 * End-to-end cover for personal dashboards.
 *
 * The rules worth protecting: a dashboard is personal, so somebody else's is
 * invisible rather than forbidden; a member always has at least one and exactly
 * one of them is the default; and a copy duplicates the arrangement without
 * duplicating the saved queries behind it.
 */
final class DashboardApiTest extends TestCase
{
    private const string PASSWORD = 'A unique dashboard passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $tenantId;
    private string $ownerMembershipId;
    private string $otherMembershipId;

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
        $ownerId = $this->insertUser('dash-owner');
        $otherId = $this->insertUser('dash-other');
        $this->tenantId = $this->insertTenant('dash-primary');
        $roles->provisionDefaults($this->tenantId, $ownerId);
        $this->ownerMembershipId = $this->addMembership(
            $this->tenantId,
            $ownerId,
            DefaultRole::TenantOwner,
        );
        $this->otherMembershipId = $this->addMembership(
            $this->tenantId,
            $otherId,
            DefaultRole::Member,
        );
        self::assertNotSame('', $this->ownerMembershipId);
        self::assertNotSame('', $this->otherMembershipId);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testFirstDashboardBecomesTheDefault(): void
    {
        $login = $this->login('dash-owner');

        $first = $this->create($login, 'My work');
        self::assertSame(201, $first->getStatusCode());
        self::assertTrue($this->dashboardOf($first)['is_default'] ?? null);
        self::assertSame(0, $this->dashboardOf($first)['position'] ?? null);
        self::assertSame(0, $this->dashboardOf($first)['widget_count'] ?? null);

        // Only the first one: a member has exactly one default at any moment.
        $second = $this->create($login, 'Release watch');
        self::assertSame(201, $second->getStatusCode());
        self::assertFalse($this->dashboardOf($second)['is_default'] ?? null);
        self::assertSame(1, $this->dashboardOf($second)['position'] ?? null);
    }

    public function testNameIsUniquePerOwnerAndCaseInsensitive(): void
    {
        $login = $this->login('dash-owner');
        $this->createId($login, 'My work');

        $duplicate = $this->create($login, '  my   WORK ');
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame('DASHBOARD_NAME_TAKEN', $this->problemCode($duplicate));

        // The namespace is the owner's, so another member is unaffected.
        $other = $this->create($this->login('dash-other'), 'My work');
        self::assertSame(201, $other->getStatusCode());
    }

    /**
     * A dashboard is personal. Somebody else's is not forbidden — it is absent,
     * so the endpoint cannot be used to learn that it exists.
     */
    public function testAnotherMembersDashboardIsInvisible(): void
    {
        $owner = $this->login('dash-owner');
        $dashboardId = $this->createId($owner, 'My work');

        $other = $this->login('dash-other');
        // Listing gives the other member their own starter dashboard, never a
        // sight of this one.
        self::assertNotContains(
            $dashboardId,
            array_column($this->rows($this->list($other), 'dashboards'), 'id'),
        );

        foreach (
            [
                ['GET', $this->dashboardPath($dashboardId), null],
                ['PUT', $this->dashboardPath($dashboardId) . '/default', null],
                ['PUT', $this->dashboardPath($dashboardId) . '/active', null],
                ['DELETE', $this->dashboardPath($dashboardId), null],
                ['POST', $this->dashboardPath($dashboardId) . '/copy', ['name' => 'Stolen']],
            ] as [$method, $path, $body]
        ) {
            $request = $this->authenticatedRequest($method, $path, $other);
            $response = $this->app->handle(
                $body === null ? $request : $request->withParsedBody($body),
            );
            self::assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame('DASHBOARD_NOT_FOUND', $this->problemCode($response));
        }

        $patch = $this->app->handle(
            $this->authenticatedRequest('PATCH', $this->dashboardPath($dashboardId), $other)
                ->withParsedBody(['expected_version' => 1, 'name' => 'Stolen']),
        );
        self::assertSame(404, $patch->getStatusCode());
    }

    public function testMakingAnotherDashboardTheDefaultMovesTheFlag(): void
    {
        $login = $this->login('dash-owner');
        $first = $this->createId($login, 'My work');
        $second = $this->createId($login, 'Release watch');

        $response = $this->app->handle($this->authenticatedRequest(
            'PUT',
            $this->dashboardPath($second) . '/default',
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->dashboardOf($response)['is_default'] ?? null);

        $defaults = array_filter(
            $this->rows($this->list($login), 'dashboards'),
            static fn(array $row): bool => $row['is_default'] === true,
        );
        // Exactly one, never two and never none.
        self::assertCount(1, $defaults);
        self::assertSame($second, array_values($defaults)[0]['id'] ?? null);
        self::assertNotSame($first, array_values($defaults)[0]['id'] ?? null);
    }

    public function testTheLastDashboardCannotBeDeleted(): void
    {
        $login = $this->login('dash-owner');
        $only = $this->createId($login, 'My work');

        $response = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->dashboardPath($only),
            $login,
        ));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('LAST_DASHBOARD_REQUIRED', $this->problemCode($response));
        self::assertCount(1, $this->rows($this->list($login), 'dashboards'));
    }

    /**
     * Deleting the default would leave the member with none, so the next
     * dashboard in their order takes the flag over.
     */
    public function testDeletingTheDefaultPromotesAnother(): void
    {
        $login = $this->login('dash-owner');
        $first = $this->createId($login, 'My work');
        $second = $this->createId($login, 'Release watch');

        $response = $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->dashboardPath($first),
            $login,
        ));
        self::assertSame(204, $response->getStatusCode());

        $remaining = $this->rows($this->list($login), 'dashboards');
        self::assertCount(1, $remaining);
        self::assertSame($second, $remaining[0]['id'] ?? null);
        self::assertTrue($remaining[0]['is_default'] ?? null);
    }

    public function testRenamingCarriesTheVersionItWasMadeAgainst(): void
    {
        $login = $this->login('dash-owner');
        $dashboardId = $this->createId($login, 'My work');

        $stale = $this->app->handle(
            $this->authenticatedRequest('PATCH', $this->dashboardPath($dashboardId), $login)
                ->withParsedBody(['expected_version' => 9, 'name' => 'Renamed']),
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('DASHBOARD_VERSION_CONFLICT', $this->problemCode($stale));

        $renamed = $this->app->handle(
            $this->authenticatedRequest('PATCH', $this->dashboardPath($dashboardId), $login)
                ->withParsedBody(['expected_version' => 1, 'name' => 'Renamed', 'position' => 3]),
        );
        self::assertSame(200, $renamed->getStatusCode());
        self::assertSame('Renamed', $this->dashboardOf($renamed)['name'] ?? null);
        self::assertSame(3, $this->dashboardOf($renamed)['position'] ?? null);
        self::assertSame(2, $this->dashboardOf($renamed)['version'] ?? null);
    }

    /**
     * Reading must not move somebody's landing page: a prefetch or a link
     * preview would otherwise decide where they land next time.
     */
    public function testReadingDoesNotChangeTheActiveDashboard(): void
    {
        $login = $this->login('dash-owner');
        $first = $this->createId($login, 'My work');
        $second = $this->createId($login, 'Release watch');

        $this->app->handle($this->authenticatedRequest(
            'PUT',
            $this->dashboardPath($first) . '/active',
            $login,
        ));
        self::assertSame($first, $this->list($login)['active_dashboard_id'] ?? null);

        $this->app->handle($this->authenticatedRequest(
            'GET',
            $this->dashboardPath($second),
            $login,
        ));
        self::assertSame($first, $this->list($login)['active_dashboard_id'] ?? null);

        $this->app->handle($this->authenticatedRequest(
            'PUT',
            $this->dashboardPath($second) . '/active',
            $login,
        ));
        self::assertSame($second, $this->list($login)['active_dashboard_id'] ?? null);
    }

    /**
     * The preference points at a dashboard that may since have been deleted, so
     * the list falls back to the default rather than to nothing.
     */
    public function testActiveFallsBackToTheDefaultWhenItsTargetIsGone(): void
    {
        $login = $this->login('dash-owner');
        $first = $this->createId($login, 'My work');
        $second = $this->createId($login, 'Release watch');

        $this->app->handle($this->authenticatedRequest(
            'PUT',
            $this->dashboardPath($second) . '/active',
            $login,
        ));
        $this->app->handle($this->authenticatedRequest(
            'DELETE',
            $this->dashboardPath($second),
            $login,
        ));

        self::assertSame($first, $this->list($login)['active_dashboard_id'] ?? null);
    }

    public function testCopyDuplicatesTheArrangementButNotTheDefaultFlag(): void
    {
        $login = $this->login('dash-owner');
        $source = $this->createId($login, 'My work');

        $response = $this->app->handle(
            $this->authenticatedRequest('POST', $this->dashboardPath($source) . '/copy', $login)
                ->withParsedBody(['name' => 'My work (copy)']),
        );

        self::assertSame(201, $response->getStatusCode());
        $copy = $this->dashboardOf($response);
        self::assertSame('My work (copy)', $copy['name'] ?? null);
        // The original keeps the default; a copy never steals it.
        self::assertFalse($copy['is_default'] ?? null);
        self::assertNotSame($source, $copy['id'] ?? null);
        self::assertCount(2, $this->rows($this->list($login), 'dashboards'));
    }

    public function testCopyRefusesACollidingName(): void
    {
        $login = $this->login('dash-owner');
        $source = $this->createId($login, 'My work');

        $response = $this->app->handle(
            $this->authenticatedRequest('POST', $this->dashboardPath($source) . '/copy', $login)
                ->withParsedBody(['name' => 'My work']),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('DASHBOARD_NAME_TAKEN', $this->problemCode($response));
    }

    private function create(ResponseInterface $login, string $name): ResponseInterface
    {
        return $this->app->handle(
            $this->authenticatedRequest('POST', $this->collectionPath(), $login)
                ->withParsedBody(['name' => $name]),
        );
    }

    private function createId(ResponseInterface $login, string $name): string
    {
        $response = $this->create($login, $name);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->dashboardOf($response)['id'] ?? null;
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
    private function dashboardOf(ResponseInterface $response): array
    {
        $payload = $this->decode($response);
        $dashboard = $payload['dashboard'] ?? null;
        self::assertIsArray($dashboard);

        $result = [];

        foreach ($dashboard as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function collectionPath(): string
    {
        return sprintf('/api/v1/tenants/%s/dashboards', $this->tenantId);
    }

    private function dashboardPath(string $dashboardId): string
    {
        return sprintf('%s/%s', $this->collectionPath(), $dashboardId);
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
