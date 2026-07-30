<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Every tenant-scoped route, addressed with somebody else's tenant.
 *
 * The individual API tests each prove isolation for the endpoint they cover.
 * This one proves it for the endpoints **nobody thought about**: it reads the
 * route table itself, so a route added tomorrow is in this suite tomorrow, and a
 * missing tenant check fails here rather than in production.
 *
 * The caller is a real, active member — of the wrong tenant. That is the case
 * that matters: an anonymous request is refused by authentication long before
 * any of this, and a member with no membership anywhere would be refused for the
 * wrong reason.
 */
final class TenantIsolationApiTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $homeTenantId;
    private string $foreignTenantId;
    private ResponseInterface $login;

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

        $intruderId = $this->insertUser('isolation-intruder');
        $strangerId = $this->insertUser('isolation-stranger');
        $this->homeTenantId = $this->insertTenant('Isolation Home');
        $this->foreignTenantId = $this->insertTenant('Isolation Foreign');

        // The intruder owns their own tenant, so they hold every tenant
        // permission the catalog has — in the wrong tenant. Anything that leaks
        // here leaks because of the tenant check, not the permission check.
        $roles->provisionDefaults($this->homeTenantId, $intruderId);
        $roles->provisionDefaults($this->foreignTenantId, $strangerId);
        $this->addMembership($this->homeTenantId, $intruderId, DefaultRole::TenantOwner);
        $this->addMembership($this->foreignTenantId, $strangerId, DefaultRole::TenantOwner);

        $this->login = $this->signIn('isolation-intruder');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    /**
     * The route table is read from the application itself, so this list grows
     * with the API rather than with somebody's memory of it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function tenantScopedRoutes(): iterable
    {
        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 2));

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();

            if (!str_contains($pattern, '{tenantId}')) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                if ($method === 'OPTIONS') {
                    continue;
                }

                yield sprintf('%s %s', $method, $pattern) => [$method, $pattern];
            }
        }
    }

    /**
     * `403` or `404` — which one depends on whether the endpoint may admit the
     * tenant exists at all. Anything else is a finding: a `2xx` is a leak, and
     * a `422` means the request body was read before the tenant was checked.
     */
    #[DataProvider('tenantScopedRoutes')]
    public function testForeignTenantIsRefused(string $method, string $pattern): void
    {
        $uri = $this->fill($pattern, $this->foreignTenantId);
        $response = $this->app->handle($this->authenticated($method, $uri));
        $status = $response->getStatusCode();

        // `403` or `404`, nothing else. Not merely "an error": a `422` would
        // mean the body was read before the tenant was checked, and an
        // endpoint that validates first is one refactor away from answering.
        self::assertContains(
            $status,
            [403, 404],
            sprintf(
                '%s %s answered %d for a tenant the caller is not a member of.',
                $method,
                $uri,
                $status,
            ),
        );

        // Nothing of the other tenant may travel back, not even its name in an
        // error message.
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Isolation Foreign', $body);
    }

    /**
     * The same routes with the caller's **own** tenant. This is the control: if
     * everything answered `404` regardless of tenant, the test above would pass
     * while proving nothing at all.
     */
    public function testTheSameRoutesAnswerForTheCallersOwnTenant(): void
    {
        $reachable = 0;

        foreach (self::tenantScopedRoutes() as [$method, $pattern]) {
            if ($method !== 'GET' || substr_count($pattern, '{') > 1) {
                // Collection reads only: an identifier this test invents cannot
                // name a row that exists, and a write would need a valid body.
                continue;
            }

            $uri = $this->fill($pattern, $this->homeTenantId);
            $status = $this->app->handle($this->authenticated($method, $uri))->getStatusCode();

            if ($status === 200) {
                ++$reachable;
            }
        }

        self::assertGreaterThan(
            5,
            $reachable,
            'The control found almost nothing reachable, so the refusals above prove nothing.',
        );
    }

    /** Substitutes the tenant and invents a well-formed identifier for the rest. */
    private function fill(string $pattern, string $tenantId): string
    {
        $uri = str_replace('{tenantId}', $tenantId, $pattern);

        return (string) preg_replace_callback(
            '/\{[^}]+\}/u',
            static fn(): string => (string) UuidV7::generate(),
            $uri,
        );
    }

    private function authenticated(string $method, string $uri): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withCookieParams(['sova_session' => $this->cookieValue($this->login, 'sova_session')])
            ->withHeader('X-CSRF-Token', $this->cookieValue($this->login, 'sova_csrf'));

        return in_array($method, ['POST', 'PUT', 'PATCH'], true)
            ? $request->withParsedBody([])
            : $request;
    }

    private function signIn(string $prefix): ResponseInterface
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/v1/auth/login')
                ->withParsedBody([
                    'email' => sprintf('%s@example.test', $prefix),
                    'password' => self::PASSWORD,
                ]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $response;
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
            'display_name' => ucfirst($prefix),
            'preferred_locale' => 'sk',
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertTenant(string $name): string
    {
        $id = (string) UuidV7::generate();

        $this->connection->insert('tenants', [
            'id' => $id,
            'slug' => sprintf('%s-%s', strtolower(str_replace(' ', '-', $name)), substr($id, -6)),
            'name' => $name,
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function addMembership(string $tenantId, string $userId, DefaultRole $role): string
    {
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

    private function cookieValue(ResponseInterface $response, string $name): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                $value = explode(';', substr($header, strlen($name) + 1), 2)[0];

                return $value;
            }
        }

        self::fail(sprintf('Cookie "%s" was not set.', $name));
    }
}
