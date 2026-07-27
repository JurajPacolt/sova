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

final class ImpersonationApiTest extends TestCase
{
    private const PASSWORD = 'A unique impersonation API passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $superadminId;
    private string $memberId;
    private string $tenantId;
    private string $foreignTenantId;
    private string $memberMembershipId;

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
        $this->superadminId = $this->insertUser('impersonation-superadmin');
        $this->memberId = $this->insertUser('impersonation-member');
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->superadminId,
            'role_code' => DefaultRole::Superadmin->value,
            'granted_by_user_id' => $this->superadminId,
        ]);
        [$this->tenantId] = $this->insertTenant(
            'impersonation-primary',
        );
        [$this->foreignTenantId] = $this->insertTenant(
            'impersonation-foreign',
        );
        $roles->provisionDefaults($this->tenantId, $this->superadminId);
        $roles->provisionDefaults(
            $this->foreignTenantId,
            $this->superadminId,
        );
        $this->memberMembershipId = $this->addMembership(
            $this->tenantId,
            $this->memberId,
            DefaultRole::Member,
        );
        $this->addMembership(
            $this->foreignTenantId,
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

    public function testStartScopesPermissionsAndEndRestoresTheActor(): void
    {
        $login = $this->login('impersonation-superadmin');
        $started = $this->start($login);
        $payload = $this->decode($started);
        $impersonation = $payload['impersonation'] ?? null;

        self::assertSame(201, $started->getStatusCode());
        self::assertIsArray($impersonation);
        $actor = $impersonation['actor'] ?? null;
        $effectiveUser = $impersonation['effective_user'] ?? null;
        $tenant = $impersonation['tenant'] ?? null;
        $user = $payload['user'] ?? null;
        self::assertIsArray($actor);
        self::assertIsArray($effectiveUser);
        self::assertIsArray($tenant);
        self::assertIsArray($user);
        self::assertSame('ACTIVE', $impersonation['status'] ?? null);
        self::assertSame(
            $this->superadminId,
            $actor['id'] ?? null,
        );
        self::assertSame(
            $this->memberId,
            $effectiveUser['id'] ?? null,
        );
        self::assertSame(
            $this->tenantId,
            $tenant['id'] ?? null,
        );
        self::assertFalse($user['is_superadmin'] ?? true);

        $current = $this->get('/api/v1/auth/session', $login);
        $currentPayload = $this->decode($current);
        $currentUser = $currentPayload['user'] ?? null;
        self::assertIsArray($currentUser);
        self::assertSame(
            $this->memberId,
            $currentUser['id'] ?? null,
        );
        self::assertFalse($currentUser['is_superadmin'] ?? true);
        self::assertIsArray($currentPayload['impersonation'] ?? null);

        $tenantList = $this->decode(
            $this->get('/api/v1/tenants', $login),
        )['tenants'] ?? null;
        self::assertIsArray($tenantList);
        self::assertCount(1, $tenantList);
        $listedTenant = $tenantList[0] ?? null;
        self::assertIsArray($listedTenant);
        self::assertSame($this->tenantId, $listedTenant['id'] ?? null);
        self::assertSame(
            200,
            $this->get(
                sprintf('/api/v1/tenants/%s', $this->tenantId),
                $login,
            )->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->get(
                sprintf('/api/v1/tenants/%s', $this->foreignTenantId),
                $login,
            )->getStatusCode(),
        );
        self::assertSame(
            403,
            $this->get('/api/v1/system/tenants', $login)->getStatusCode(),
        );
        self::assertSame(
            403,
            $this->get(
                sprintf('/api/v1/tenants/%s/audit', $this->tenantId),
                $login,
            )->getStatusCode(),
        );

        self::assertGreaterThanOrEqual(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE actor_user_id = :actor_user_id
                        AND effective_user_id = :effective_user_id
                        AND tenant_id = :tenant_id
                        AND event_type = 'IMPERSONATION_REQUEST'
                    SQL,
                [
                    'actor_user_id' => $this->superadminId,
                    'effective_user_id' => $this->memberId,
                    'tenant_id' => $this->tenantId,
                ],
            ),
        );

        $ended = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                '/api/v1/system/impersonations/current',
                $login,
            ),
        );
        self::assertSame(204, $ended->getStatusCode());
        $restored = $this->decode(
            $this->get('/api/v1/auth/session', $login),
        );
        $restoredUser = $restored['user'] ?? null;
        self::assertIsArray($restoredUser);
        self::assertSame(
            $this->superadminId,
            $restoredUser['id'] ?? null,
        );
        self::assertTrue($restoredUser['is_superadmin'] ?? false);
        self::assertNull($restored['impersonation'] ?? null);
        self::assertSame(
            200,
            $this->get('/api/v1/system/tenants', $login)->getStatusCode(),
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE actor_user_id = :actor_user_id
                        AND effective_user_id = :effective_user_id
                        AND tenant_id = :tenant_id
                        AND event_type = 'IMPERSONATION_ENDED'
                    SQL,
                [
                    'actor_user_id' => $this->superadminId,
                    'effective_user_id' => $this->memberId,
                    'tenant_id' => $this->tenantId,
                ],
            ),
        );
    }

    public function testStartRequiresSuperadminAndFreshPassword(): void
    {
        $memberLogin = $this->login('impersonation-member');
        $denied = $this->start($memberLogin);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($denied)['code']);

        $superadminLogin = $this->login('impersonation-superadmin');
        $reauthenticationFailed = $this->start(
            $superadminLogin,
            'incorrect current password',
        );
        self::assertSame(401, $reauthenticationFailed->getStatusCode());
        self::assertSame(
            'IMPERSONATION_REAUTHENTICATION_FAILED',
            $this->decode($reauthenticationFailed)['code'],
        );
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM impersonations WHERE ended_at IS NULL',
        ));
    }

    public function testRevokingCurrentSessionEndsImpersonation(): void
    {
        $login = $this->login('impersonation-superadmin');
        $loginPayload = $this->decode($login);
        $session = $loginPayload['session'] ?? null;
        self::assertIsArray($session);
        $sessionId = $session['id'] ?? null;
        self::assertIsString($sessionId);
        self::assertSame(201, $this->start($login)->getStatusCode());

        $revoked = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                sprintf('/api/v1/auth/sessions/%s', $sessionId),
                $login,
            ),
        );

        self::assertSame(204, $revoked->getStatusCode());
        self::assertNotEmpty($this->connection->fetchOne(
            'SELECT revoked_at FROM user_sessions WHERE id = :session_id',
            ['session_id' => $sessionId],
        ));
        self::assertSame(
            'SESSION_REVOKED',
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT end_reason
                    FROM impersonations
                    WHERE session_id = :session_id
                    SQL,
                ['session_id' => $sessionId],
            ),
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE actor_user_id = :actor_user_id
                        AND effective_user_id = :effective_user_id
                        AND tenant_id = :tenant_id
                        AND event_type = 'IMPERSONATION_ENDED'
                        AND reason_code = 'SESSION_REVOKED'
                    SQL,
                [
                    'actor_user_id' => $this->superadminId,
                    'effective_user_id' => $this->memberId,
                    'tenant_id' => $this->tenantId,
                ],
            ),
        );
    }

    public function testExpiredAndInvalidatedContextsAreBlockedUntilEnded(): void
    {
        $login = $this->login('impersonation-superadmin');
        self::assertSame(201, $this->start($login)->getStatusCode());
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE impersonations
                SET reauthenticated_at = started_at - INTERVAL '16 minutes',
                    started_at = started_at - INTERVAL '16 minutes',
                    expires_at = expires_at - INTERVAL '16 minutes'
                WHERE ended_at IS NULL
                SQL,
        );

        $expired = $this->get(
            sprintf('/api/v1/tenants/%s', $this->tenantId),
            $login,
        );
        self::assertSame(409, $expired->getStatusCode());
        self::assertSame('IMPERSONATION_EXPIRED', $this->decode($expired)['code']);
        $expiredCurrent = $this->decode(
            $this->get('/api/v1/auth/session', $login),
        );
        $expiredContext = $expiredCurrent['impersonation'] ?? null;
        self::assertIsArray($expiredContext);
        self::assertSame('EXPIRED', $expiredContext['status'] ?? null);
        self::assertSame(
            204,
            $this->app->handle(
                $this->authenticatedRequest(
                    'DELETE',
                    '/api/v1/system/impersonations/current',
                    $login,
                ),
            )->getStatusCode(),
        );

        self::assertSame(201, $this->start($login)->getStatusCode());
        $this->connection->update(
            'tenant_memberships',
            ['status' => 'DISABLED'],
            ['id' => $this->memberMembershipId],
        );
        $invalidated = $this->get(
            sprintf('/api/v1/tenants/%s', $this->tenantId),
            $login,
        );
        self::assertSame(409, $invalidated->getStatusCode());
        self::assertSame(
            'IMPERSONATION_INVALIDATED',
            $this->decode($invalidated)['code'],
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

    /**
     * @return array{string, string}
     */
    private function insertTenant(string $slug): array
    {
        $id = (string) UuidV7::generate();
        $uniqueSlug = sprintf('%s-%s', $slug, substr($id, 0, 8));
        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $uniqueSlug,
            'status' => 'ACTIVE',
        ]);

        return [$id, $uniqueSlug];
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
                SELECT :tenant_id, :membership_id, id, :actor_user_id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :role_code
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'actor_user_id' => $this->superadminId,
                'role_code' => $role->value,
            ],
        );

        return $membershipId;
    }

    private function start(
        ResponseInterface $login,
        string $password = self::PASSWORD,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                '/api/v1/system/impersonations',
                $login,
            )->withParsedBody([
                'tenant_id' => $this->tenantId,
                'effective_user_id' => $this->memberId,
                'reason' => 'Investigating support request SOVA-42.',
                'password' => $password,
            ]),
        );
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
            ['REMOTE_ADDR' => '203.0.113.120'],
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
