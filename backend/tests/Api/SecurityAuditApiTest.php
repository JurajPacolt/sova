<?php

declare(strict_types=1);

namespace Sova\Tests\Api;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
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

final class SecurityAuditApiTest extends TestCase
{
    private const PASSWORD = 'A unique security audit passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $superadminId;
    private string $ownerId;
    private string $memberId;
    private string $tenantId;
    private string $foreignTenantId;

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
        $this->superadminId = $this->insertUser('audit-superadmin');
        $this->ownerId = $this->insertUser('audit-owner');
        $this->memberId = $this->insertUser('audit-member');
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->superadminId,
            'role_code' => DefaultRole::Superadmin->value,
            'granted_by_user_id' => $this->superadminId,
        ]);
        $this->tenantId = $this->insertTenant('audit-primary');
        $this->foreignTenantId = $this->insertTenant('audit-foreign');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->addMembership(
            $this->tenantId,
            $this->ownerId,
            DefaultRole::TenantOwner,
        );
        $this->addMembership(
            $this->tenantId,
            $this->memberId,
            DefaultRole::Member,
        );
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

    public function testSystemAuditRequiresSuperadminAndSupportsKeysetFilters(): void
    {
        $this->insertAuditEvent(
            'AUDIT_TEST_EVENT',
            $this->tenantId,
            '2026-07-27 10:00:00.000001+00',
            ['access_token' => 'must-not-leak', 'revision' => 1],
        );
        $this->insertAuditEvent(
            'AUDIT_TEST_EVENT',
            $this->foreignTenantId,
            '2026-07-27 09:00:00.000001+00',
            ['revision' => 2],
        );

        $denied = $this->get(
            '/api/v1/system/audit',
            $this->login('audit-owner'),
        );
        self::assertSame(403, $denied->getStatusCode());

        $login = $this->login('audit-superadmin');
        $first = $this->get(
            '/api/v1/system/audit?event_type=AUDIT_TEST_EVENT&limit=1',
            $login,
        );
        $firstPayload = $this->decode($first);
        $firstEvents = $firstPayload['events'] ?? null;
        self::assertSame(200, $first->getStatusCode());
        self::assertIsArray($firstEvents);
        self::assertCount(1, $firstEvents);
        $firstEvent = $firstEvents[0] ?? null;
        self::assertIsArray($firstEvent);
        $firstMetadata = $firstEvent['metadata'] ?? null;
        self::assertIsArray($firstMetadata);
        self::assertSame(
            '[REDACTED]',
            $firstMetadata['access_token'] ?? null,
        );
        $cursor = $firstPayload['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $second = $this->get(
            sprintf(
                '/api/v1/system/audit?event_type=AUDIT_TEST_EVENT&limit=1&cursor=%s',
                rawurlencode($cursor),
            ),
            $login,
        );
        $secondPayload = $this->decode($second);
        $secondEvents = $secondPayload['events'] ?? null;
        self::assertSame(200, $second->getStatusCode());
        self::assertIsArray($secondEvents);
        self::assertCount(1, $secondEvents);
        self::assertNull($secondPayload['next_cursor'] ?? null);
        $secondEvent = $secondEvents[0] ?? null;
        self::assertIsArray($secondEvent);
        self::assertNotSame(
            $firstEvent['id'] ?? null,
            $secondEvent['id'] ?? null,
        );
    }

    public function testTenantAuditIsPermissionCheckedAndTenantScoped(): void
    {
        $this->insertAuditEvent(
            'TENANT_SCOPE_TEST',
            $this->tenantId,
            '2026-07-27 11:00:00+00',
        );
        $this->insertAuditEvent(
            'TENANT_SCOPE_TEST',
            $this->foreignTenantId,
            '2026-07-27 12:00:00+00',
        );

        $member = $this->get(
            sprintf('/api/v1/tenants/%s/audit', $this->tenantId),
            $this->login('audit-member'),
        );
        self::assertSame(403, $member->getStatusCode());

        $owner = $this->get(
            sprintf(
                '/api/v1/tenants/%s/audit?event_type=TENANT_SCOPE_TEST',
                $this->tenantId,
            ),
            $this->login('audit-owner'),
        );
        $events = $this->decode($owner)['events'] ?? null;
        self::assertSame(200, $owner->getStatusCode());
        self::assertIsArray($events);
        self::assertCount(1, $events);
        $event = $events[0] ?? null;
        self::assertIsArray($event);
        $tenant = $event['tenant'] ?? null;
        self::assertIsArray($tenant);
        self::assertSame(
            $this->tenantId,
            $tenant['id'] ?? null,
        );
    }

    public function testTenantAuditExportRequiresPermissionAndReturnsCsv(): void
    {
        $this->insertAuditEvent(
            'TENANT_EXPORT_TEST',
            $this->tenantId,
            '2026-07-27 14:00:00+00',
        );
        $this->insertAuditEvent(
            'TENANT_EXPORT_TEST',
            $this->foreignTenantId,
            '2026-07-27 15:00:00+00',
        );

        $member = $this->get(
            sprintf('/api/v1/tenants/%s/audit/export', $this->tenantId),
            $this->login('audit-member'),
        );
        self::assertSame(403, $member->getStatusCode());

        $owner = $this->get(
            sprintf(
                '/api/v1/tenants/%s/audit/export?event_type=TENANT_EXPORT_TEST',
                $this->tenantId,
            ),
            $this->login('audit-owner'),
        );
        self::assertSame(200, $owner->getStatusCode());
        self::assertStringStartsWith(
            'text/csv',
            $owner->getHeaderLine('Content-Type'),
        );
        self::assertStringContainsString(
            'attachment; filename="tenant-audit-',
            $owner->getHeaderLine('Content-Disposition'),
        );

        $csv = (string) $owner->getBody();
        $lines = array_values(array_filter(explode("\n", $csv)));
        self::assertSame(
            'id,occurred_at,event_type,outcome,reason_code,actor_id,'
                . 'actor_email,actor_display_name,effective_user_id,'
                . 'effective_user_email,effective_user_display_name,'
                . 'tenant_id,tenant_name,tenant_slug,request_id,ip_address,'
                . 'metadata',
            $lines[0] ?? null,
        );
        self::assertCount(2, $lines);
        self::assertStringContainsString(
            'TENANT_EXPORT_TEST',
            $lines[1] ?? '',
        );
        self::assertStringContainsString($this->tenantId, $lines[1] ?? '');
        self::assertStringNotContainsString(
            $this->foreignTenantId,
            $csv,
        );
    }

    public function testAuditEventsCannotBeUpdatedOrDeleted(): void
    {
        $eventId = $this->insertAuditEvent(
            'IMMUTABILITY_TEST',
            $this->tenantId,
            '2026-07-27 13:00:00+00',
        );
        $authenticationEventId = (string) UuidV7::generate();
        $this->connection->insert('authentication_events', [
            'id' => $authenticationEventId,
            'user_id' => $this->ownerId,
            'event_type' => 'LOGIN',
            'outcome' => 'SUCCESS',
            'reason_code' => 'TEST_FIXTURE',
            'request_id' => sprintf(
                'auth-test-%s',
                substr($authenticationEventId, 0, 8),
            ),
        ]);
        $this->connection->createSavepoint('before_audit_update');

        try {
            $this->connection->update(
                'security_audit_events',
                ['outcome' => 'FAILURE'],
                ['id' => $eventId],
            );
            self::fail('Audit updates must be rejected by PostgreSQL.');
        } catch (DbalException) {
            $this->connection->rollbackSavepoint('before_audit_update');
        }

        $this->connection->createSavepoint('before_audit_delete');

        try {
            $this->connection->delete(
                'security_audit_events',
                ['id' => $eventId],
            );
            self::fail('Audit deletes must be rejected by PostgreSQL.');
        } catch (DbalException) {
            $this->connection->rollbackSavepoint('before_audit_delete');
        }

        $this->connection->createSavepoint('before_auth_audit_update');

        try {
            $this->connection->update(
                'authentication_events',
                ['outcome' => 'FAILURE'],
                ['id' => $authenticationEventId],
            );
            self::fail(
                'Authentication audit updates must be rejected by PostgreSQL.',
            );
        } catch (DbalException) {
            $this->connection->rollbackSavepoint(
                'before_auth_audit_update',
            );
        }

        $this->connection->createSavepoint('before_auth_audit_delete');

        try {
            $this->connection->delete(
                'authentication_events',
                ['id' => $authenticationEventId],
            );
            self::fail(
                'Authentication audit deletes must be rejected by PostgreSQL.',
            );
        } catch (DbalException) {
            $this->connection->rollbackSavepoint(
                'before_auth_audit_delete',
            );
        }

        self::assertSame(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE id = :id',
            ['id' => $eventId],
        ));
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM authentication_events WHERE id = :id',
            ['id' => $authenticationEventId],
        ));
    }

    public function testInvalidAuditFilterReturnsValidationProblem(): void
    {
        $response = $this->get(
            '/api/v1/system/audit?limit=101&outcome=MAYBE',
            $this->login('audit-superadmin'),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'AUDIT_QUERY_INVALID',
            $this->decode($response)['code'],
        );
    }

    private function insertUser(string $prefix): string
    {
        $id = (string) UuidV7::generate();
        $email = sprintf('%s@example.test', $prefix);
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => (new Argon2idPasswordHasher())->hash(
                self::PASSWORD,
            ),
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
    ): void {
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
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    private function insertAuditEvent(
        string $eventType,
        ?string $tenantId,
        string $occurredAt,
        array $metadata = [],
    ): string {
        $id = (string) UuidV7::generate();
        $this->connection->insert('security_audit_events', [
            'id' => $id,
            'actor_user_id' => $this->ownerId,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'outcome' => 'SUCCESS',
            'reason_code' => 'TEST_FIXTURE',
            'request_id' => sprintf('audit-test-%s', substr($id, 0, 8)),
            'metadata' => json_encode(
                (object) $metadata,
                JSON_THROW_ON_ERROR,
            ),
            'occurred_at' => $occurredAt,
        ]);

        return $id;
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

    private function get(
        string $uri,
        ResponseInterface $login,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest('GET', $uri, $login),
        );
    }

    private function authenticatedRequest(
        string $method,
        string $uri,
        ResponseInterface $login,
    ): ServerRequestInterface {
        return $this->request($method, $uri)
            ->withCookieParams([
                'sova_session' => $this->cookieValue(
                    $login,
                    'sova_session',
                ),
            ])
            ->withHeader(
                'X-CSRF-Token',
                $this->cookieValue($login, 'sova_csrf'),
            );
    }

    private function request(
        string $method,
        string $uri,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $uri,
        );
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

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
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
}
