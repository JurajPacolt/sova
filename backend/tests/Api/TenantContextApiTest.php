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
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class TenantContextApiTest extends TestCase
{
    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $userId;
    private string $activeTenantId;
    private string $foreignTenantId;
    private string $disabledMembershipTenantId;
    private string $suspendedTenantId;
    private string $deletedTenantId;

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

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->userId = (string) UuidV7::generate();
        $hasher = new Argon2idPasswordHasher();

        $this->connection->insert('users', [
            'id' => $this->userId,
            'email' => 'tenant-member@example.test',
            'normalized_email' => 'tenant-member@example.test',
            'password_hash' => $hasher->hash('correct horse battery staple'),
            'display_name' => 'Tenant Member',
            'preferred_locale' => 'sk',
            'status' => 'ACTIVE',
        ]);

        $this->activeTenantId = $this->insertTenant('Alpha', 'ACTIVE');
        $this->foreignTenantId = $this->insertTenant('Beta', 'ACTIVE');
        $this->disabledMembershipTenantId = $this->insertTenant('Gamma', 'ACTIVE');
        $this->suspendedTenantId = $this->insertTenant('Suspended', 'SUSPENDED');
        $this->deletedTenantId = $this->insertTenant('Deleted', 'DELETED');
        $this->insertMembership($this->activeTenantId, 'ACTIVE');
        $this->insertMembership($this->disabledMembershipTenantId, 'DISABLED');
        $this->insertMembership($this->suspendedTenantId, 'ACTIVE');
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

    public function testTenantEndpointsRequireAnAuthenticatedSession(): void
    {
        $response = $this->app->handle(
            $this->request('GET', '/api/v1/tenants'),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('SESSION_REQUIRED', $this->decode($response)['code']);
    }

    public function testListContainsOnlyActiveTenantsWithActiveMembership(): void
    {
        $sessionToken = $this->loginSessionToken();
        $response = $this->app->handle(
            $this->request('GET', '/api/v1/tenants')
                ->withCookieParams(['sova_session' => $sessionToken]),
        );
        $payload = $this->decode($response);
        $tenants = $payload['tenants'] ?? null;

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($tenants);
        self::assertCount(1, $tenants);
        $tenant = $tenants[0] ?? null;
        self::assertIsArray($tenant);
        self::assertSame($this->activeTenantId, $tenant['id'] ?? null);
        self::assertSame('ACTIVE', $tenant['status'] ?? null);
        self::assertIsArray($tenant['access'] ?? null);
        self::assertSame('MEMBERSHIP', $tenant['access']['type'] ?? null);
        self::assertIsString($tenant['access']['membership_id'] ?? null);
    }

    public function testTenantContextHidesCrossTenantAndInactiveAccess(): void
    {
        $sessionToken = $this->loginSessionToken();
        $active = $this->tenantDetail($this->activeTenantId, $sessionToken);

        self::assertSame(200, $active->getStatusCode());
        $activeTenant = $this->decode($active)['tenant'] ?? null;
        self::assertIsArray($activeTenant);
        self::assertSame($this->activeTenantId, $activeTenant['id'] ?? null);

        foreach ([
            $this->foreignTenantId,
            $this->disabledMembershipTenantId,
            $this->suspendedTenantId,
            'not-a-uuid',
        ] as $tenantId) {
            $response = $this->tenantDetail($tenantId, $sessionToken);

            self::assertSame(404, $response->getStatusCode());
            self::assertSame('TENANT_NOT_FOUND', $this->decode($response)['code']);
        }
    }

    public function testSuperadminCanAccessEveryNonDeletedTenantWithoutMembership(): void
    {
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->userId,
            'role_code' => 'SUPERADMIN',
        ]);
        $sessionToken = $this->loginSessionToken();
        $listResponse = $this->app->handle(
            $this->request('GET', '/api/v1/tenants')
                ->withCookieParams(['sova_session' => $sessionToken]),
        );
        $payload = $this->decode($listResponse);
        $tenants = $payload['tenants'] ?? null;

        self::assertSame(200, $listResponse->getStatusCode());
        self::assertIsArray($tenants);
        self::assertCount(4, $tenants);

        $tenantIds = [];

        foreach ($tenants as $tenant) {
            self::assertIsArray($tenant);
            $tenantId = $tenant['id'] ?? null;
            self::assertIsString($tenantId);
            $tenantIds[] = $tenantId;
            $access = $tenant['access'] ?? null;
            self::assertIsArray($access);
            self::assertSame('SUPERADMIN', $access['type'] ?? null);
            self::assertNull($access['membership_id'] ?? null);
        }

        self::assertContains($this->foreignTenantId, $tenantIds);
        self::assertContains($this->suspendedTenantId, $tenantIds);
        self::assertNotContains($this->deletedTenantId, $tenantIds);

        $suspended = $this->tenantDetail($this->suspendedTenantId, $sessionToken);

        self::assertSame(200, $suspended->getStatusCode());
        $suspendedTenant = $this->decode($suspended)['tenant'] ?? null;
        self::assertIsArray($suspendedTenant);
        self::assertSame('SUSPENDED', $suspendedTenant['status'] ?? null);

        $invalid = $this->tenantDetail('not-a-uuid', $sessionToken);
        self::assertSame(404, $invalid->getStatusCode());
        self::assertSame('TENANT_NOT_FOUND', $this->decode($invalid)['code']);

        self::assertSame(3, $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM security_audit_events
                WHERE actor_user_id = :actor_user_id
                    AND event_type IN (
                        'SUPERADMIN_TENANTS_LIST_VIEWED',
                        'SUPERADMIN_TENANT_CONTEXT_ENTERED'
                    )
                SQL,
            ['actor_user_id' => $this->userId],
        ));
    }

    private function loginSessionToken(): string
    {
        $response = $this->app->handle(
            $this->request('POST', '/api/v1/auth/login')
                ->withParsedBody([
                    'email' => 'tenant-member@example.test',
                    'password' => 'correct horse battery staple',
                ]),
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->cookieValue($response, 'sova_session');
    }

    private function tenantDetail(
        string $tenantId,
        string $sessionToken,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request('GET', sprintf('/api/v1/tenants/%s', $tenantId))
                ->withCookieParams(['sova_session' => $sessionToken]),
        );
    }

    private function insertTenant(string $name, string $status): string
    {
        $id = (string) UuidV7::generate();

        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => $name,
            'slug' => sprintf(
                '%s-%s',
                strtolower($name),
                substr(str_replace('-', '', $id), 0, 8),
            ),
            'status' => $status,
        ]);

        return $id;
    }

    private function insertMembership(string $tenantId, string $status): void
    {
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $this->userId,
            'status' => $status,
        ]);
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $uri,
            ['REMOTE_ADDR' => '203.0.113.20'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode(
            $response->getBody()->__toString(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            self::fail('Expected a JSON object response.');
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                self::fail('Expected JSON object keys to be strings.');
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function cookieValue(
        ResponseInterface $response,
        string $cookieName,
    ): string {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (!str_starts_with($header, sprintf('%s=', $cookieName))) {
                continue;
            }

            $pair = explode(';', $header, 2)[0];
            $value = substr($pair, strlen($cookieName) + 1);

            return rawurldecode($value);
        }

        self::fail(sprintf('Cookie "%s" was not set.', $cookieName));
    }
}
