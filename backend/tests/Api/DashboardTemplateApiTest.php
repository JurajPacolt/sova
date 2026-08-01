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
use Sova\Dashboards\Domain\Template\StarterTemplate;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

/**
 * End-to-end cover for the starter dashboard template (spec §7.5).
 *
 * The rules worth protecting: a member who has never opened dashboards is given
 * one and only ever one, the queries it needs are created as *their own*
 * private queries, restoring adds instead of overwriting, and none of it
 * happens for somebody who was not allowed to create these things by hand.
 */
final class DashboardTemplateApiTest extends TestCase
{
    private const string PASSWORD = 'A unique starter passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $tenantId;
    private string $memberMembershipId;
    private string $reporterMembershipId;

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
        $ownerId = $this->insertUser('starter-owner');
        $memberId = $this->insertUser('starter-member');
        $reporterId = $this->insertUser('starter-reporter');
        $this->tenantId = $this->insertTenant('starter-primary');
        $roles->provisionDefaults($this->tenantId, $ownerId);
        $this->addMembership($this->tenantId, $ownerId, DefaultRole::TenantOwner);
        $this->memberMembershipId = $this->addMembership(
            $this->tenantId,
            $memberId,
            DefaultRole::Member,
        );
        $this->reporterMembershipId = $this->addMembership(
            $this->tenantId,
            $reporterId,
            DefaultRole::Reporter,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    /**
     * Every active member must end up with at least one dashboard (spec §7.2),
     * and the server is what makes that true — not whichever client remembered
     * to ask.
     */
    public function testFirstListingProvisionsTheStarterDashboard(): void
    {
        $login = $this->login('starter-member');
        $payload = $this->list($login);
        $dashboards = $this->rows($payload, 'dashboards');

        self::assertCount(1, $dashboards);
        self::assertSame(StarterTemplate::DASHBOARD_NAME, $dashboards[0]['name'] ?? null);
        self::assertTrue($dashboards[0]['is_default'] ?? null);
        self::assertSame(count(StarterTemplate::widgets()), $dashboards[0]['widget_count'] ?? null);
        // It is where the member lands, because an absent preference resolves
        // to their default.
        self::assertSame($dashboards[0]['id'] ?? null, $payload['active_dashboard_id'] ?? null);

        // …but the preference itself was not written. A prefetched or repeated
        // listing must never move where somebody lands next.
        self::assertSame(
            0,
            $this->countOwnedRows('membership_dashboard_preferences', $this->memberMembershipId),
        );

        // The queries are the member's own private ones, not a shared fixture
        // somebody else could rename or archive under them.
        $queries = $this->rows($this->savedQueries($login), 'saved_queries');
        self::assertCount(count(StarterTemplate::queries()), $queries);

        foreach ($queries as $query) {
            self::assertSame('PRIVATE', $query['visibility'] ?? null);
            self::assertTrue($query['viewer_is_owner'] ?? null);
        }
    }

    public function testProvisioningHappensOnceHoweverOftenTheListIsFetched(): void
    {
        $login = $this->login('starter-member');
        $first = $this->rows($this->list($login), 'dashboards');
        $second = $this->rows($this->list($login), 'dashboards');
        $third = $this->rows($this->list($login), 'dashboards');

        self::assertCount(1, $second);
        self::assertCount(1, $third);
        self::assertSame($first[0]['id'] ?? null, $third[0]['id'] ?? null);
        // And no second helping of queries either.
        self::assertCount(
            count(StarterTemplate::queries()),
            $this->rows($this->savedQueries($login), 'saved_queries'),
        );
    }

    /**
     * The widgets land where the manifest puts them, each pointing at the query
     * the manifest paired it with — copied into this member's own rows rather
     * than linked to anything shared.
     */
    public function testWidgetsMatchTheManifestLayoutAndSources(): void
    {
        $login = $this->login('starter-member');
        $dashboardId = $this->starterDashboardId($login);
        $widgets = $this->rows($this->widgets($login, $dashboardId), 'widgets');
        $manifest = StarterTemplate::widgets();

        self::assertCount(count($manifest), $widgets);

        $byTitle = [];

        foreach ($widgets as $widget) {
            $title = $widget['title'] ?? null;
            self::assertIsString($title);
            $byTitle[$title] = $widget;
        }

        foreach ($manifest as $expected) {
            $widget = $byTitle[$expected->title] ?? null;
            self::assertIsArray($widget, $expected->title);
            self::assertSame($expected->type->value, $widget['type_key'] ?? null);
            // Compared by content: `jsonb` keeps the pairs, not the order they
            // were written in.
            self::assertEquals($expected->configuration, $widget['configuration'] ?? null);
            self::assertSame($expected->x, $widget['x'] ?? null, $expected->title);
            self::assertSame($expected->y, $widget['y'] ?? null, $expected->title);
            self::assertSame($expected->width, $widget['width'] ?? null, $expected->title);
            self::assertSame($expected->height, $widget['height'] ?? null, $expected->title);
            // A widget renders a query the member can reach; the template is no
            // exception to that.
            self::assertTrue($widget['source_reachable'] ?? null, $expected->title);
        }

        $sources = array_unique($this->column($widgets, 'saved_query_id'));
        self::assertCount(count(StarterTemplate::queries()), $sources);
    }

    /**
     * Storable is not the same as runnable. Every preset is executed once here,
     * so a manifest that passes the language but trips the compiler or the
     * scope cannot reach anybody's first login.
     */
    public function testEveryStarterWidgetLoadsItsData(): void
    {
        $login = $this->login('starter-member');
        $dashboardId = $this->starterDashboardId($login);

        foreach ($this->rows($this->widgets($login, $dashboardId), 'widgets') as $widget) {
            $widgetId = $widget['id'] ?? null;
            $title = $widget['title'] ?? null;
            self::assertIsString($widgetId);
            self::assertIsString($title);

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

            self::assertSame(200, $response->getStatusCode(), $title);
        }
    }

    /**
     * Restoring hands back a working starting point; it does not reset anybody.
     * The earlier dashboard, its widgets, its queries, the default flag and the
     * member's place all survive untouched (spec §7.5).
     */
    public function testRestoreAddsAFreshCopyAndLeavesTheOldOneAlone(): void
    {
        $login = $this->login('starter-member');
        $originalId = $this->starterDashboardId($login);
        $originalSources = $this->column(
            $this->rows($this->widgets($login, $originalId), 'widgets'),
            'saved_query_id',
        );

        $response = $this->restore($login, null);
        self::assertSame(201, $response->getStatusCode());

        $payload = $this->decode($response);
        $restored = $payload['dashboard'] ?? null;
        self::assertIsArray($restored);
        self::assertNotSame($originalId, $restored['id'] ?? null);
        // The name counts up rather than colliding: the template is the path
        // for people who would rather not name things first.
        self::assertSame(StarterTemplate::DASHBOARD_NAME . ' 2', $restored['name'] ?? null);
        // And it does not take over. Where somebody lands is their decision.
        self::assertFalse($restored['is_default'] ?? null);
        self::assertFalse($restored['is_active'] ?? null);

        $restoredWidgets = $this->rows($payload, 'widgets');
        self::assertCount(count(StarterTemplate::widgets()), $restoredWidgets);

        // New private queries, not a second dashboard aimed at the first one's:
        // editing the copy must not rewrite what the original renders.
        $restoredSources = $this->column($restoredWidgets, 'saved_query_id');
        self::assertSame([], array_intersect($originalSources, $restoredSources));

        $names = $this->column($this->rows($this->savedQueries($login), 'saved_queries'), 'name');
        self::assertCount(2 * count(StarterTemplate::queries()), $names);
        self::assertContains(StarterTemplate::queries()[0]->name, $names);
        self::assertContains(StarterTemplate::queries()[0]->name . ' 2', $names);

        // The original is intact, still the default, still first.
        $dashboards = $this->rows($this->list($login), 'dashboards');
        self::assertCount(2, $dashboards);
        $original = array_values(array_filter(
            $dashboards,
            static fn(array $row): bool => $row['id'] === $originalId,
        ));
        self::assertCount(1, $original);
        self::assertTrue($original[0]['is_default'] ?? null);
        self::assertSame(
            count(StarterTemplate::widgets()),
            $original[0]['widget_count'] ?? null,
        );
        self::assertSame(
            $originalSources,
            $this->column($this->rows($this->widgets($login, $originalId), 'widgets'), 'saved_query_id'),
        );
    }

    public function testRestoreTakesAName(): void
    {
        $login = $this->login('starter-member');
        $this->starterDashboardId($login);

        $response = $this->restore($login, 'Release watch');
        self::assertSame(201, $response->getStatusCode());
        $dashboard = $this->decode($response)['dashboard'] ?? null;
        self::assertIsArray($dashboard);
        self::assertSame('Release watch', $dashboard['name'] ?? null);
    }

    /**
     * A template is a convenience, never a way around a permission. Somebody
     * who could not create these things by hand is not handed them by opening a
     * page — an empty list is the truthful answer.
     */
    public function testAMemberWhoMayNotCreateDashboardsIsLeftAlone(): void
    {
        $login = $this->login('starter-reporter');

        self::assertSame([], $this->rows($this->list($login), 'dashboards'));
        self::assertSame(
            0,
            $this->countOwnedRows('dashboards', $this->reporterMembershipId),
        );
        self::assertSame(
            0,
            $this->countOwnedRows('saved_queries', $this->reporterMembershipId),
        );
    }

    /**
     * Asking explicitly gets an explicit answer. Quietly returning a dashboard
     * with nothing on it would leave the caller with no way of knowing why.
     */
    public function testRestoreIsRefusedWithoutThePermissions(): void
    {
        $response = $this->restore($this->login('starter-reporter'), null);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($response)['code'] ?? null);
        self::assertSame(
            0,
            $this->countOwnedRows('dashboards', $this->reporterMembershipId),
        );
    }

    /**
     * The owner column is the only variable; the table name comes from this
     * file, never from a value under test.
     */
    private function countOwnedRows(string $table, string $membershipId): int
    {
        $column = $table === 'membership_dashboard_preferences'
            ? 'membership_id'
            : 'owner_membership_id';
        $value = $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column),
            [$membershipId],
        );

        if (!is_numeric($value)) {
            self::fail(sprintf('Counting rows of "%s" returned no number.', $table));
        }

        return (int) $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function column(array $rows, string $key): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            self::assertIsString($value, $key);
            $values[] = $value;
        }

        return $values;
    }

    private function starterDashboardId(ResponseInterface $login): string
    {
        $dashboards = $this->rows($this->list($login), 'dashboards');
        self::assertCount(1, $dashboards);
        $id = $dashboards[0]['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function restore(ResponseInterface $login, ?string $name): ResponseInterface
    {
        $request = $this->authenticatedRequest(
            'POST',
            sprintf('/api/v1/tenants/%s/dashboards/from-template', $this->tenantId),
            $login,
        );

        return $this->app->handle(
            $name === null ? $request : $request->withParsedBody(['name' => $name]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function list(ResponseInterface $login): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/dashboards', $this->tenantId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function widgets(ResponseInterface $login, string $dashboardId): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf(
                '/api/v1/tenants/%s/dashboards/%s/widgets',
                $this->tenantId,
                $dashboardId,
            ),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedQueries(ResponseInterface $login): array
    {
        $response = $this->app->handle($this->authenticatedRequest(
            'GET',
            sprintf('/api/v1/tenants/%s/saved-queries', $this->tenantId),
            $login,
        ));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
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
