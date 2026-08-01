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
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class SystemTenantAdministrationApiTest extends TestCase
{
    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $userId;

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
        $this->connection->insert('users', [
            'id' => $this->userId,
            'email' => 'system-admin@example.test',
            'normalized_email' => 'system-admin@example.test',
            'password_hash' => (new Argon2idPasswordHasher())->hash(
                'correct horse battery staple',
            ),
            'display_name' => 'System administrator',
            'preferred_locale' => 'sk',
            'status' => 'ACTIVE',
        ]);
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

    public function testSystemTenantEndpointsRejectANormalUser(): void
    {
        $login = $this->login();
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                '/api/v1/system/tenants',
                $login,
            ),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($response)['code']);
    }

    public function testCreationIsAtomicIdempotentAndOwnerAcceptanceActivates(): void
    {
        $login = $this->superadminLogin();
        $idempotencyKey = '019c02d5-2df0-7cd1-bae6-4502f9a8534a';
        $payload = [
            'name' => 'New customer',
            'slug' => 'new-customer',
            'owner_email' => 'owner@example.test',
        ];
        $created = $this->createTenant(
            $login,
            $idempotencyKey,
            $payload,
        );
        $createdPayload = $this->decode($created);
        $tenant = $createdPayload['tenant'] ?? null;

        self::assertSame(201, $created->getStatusCode());
        self::assertIsArray($tenant);
        self::assertSame('PENDING', $tenant['status'] ?? null);
        self::assertSame(1, $tenant['revision'] ?? null);
        self::assertFalse($createdPayload['replayed'] ?? true);
        $tenantId = $tenant['id'] ?? null;
        self::assertIsString($tenantId);
        self::assertSame(4, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tenant_roles WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId],
        ));
        self::assertSame('TENANT_OWNER', $this->connection->fetchOne(
            <<<'SQL'
                SELECT initial_role_code
                FROM tenant_invitations
                WHERE tenant_id = :tenant_id
                SQL,
            ['tenant_id' => $tenantId],
        ));

        $replayed = $this->createTenant(
            $login,
            $idempotencyKey,
            $payload,
        );
        self::assertSame(200, $replayed->getStatusCode());
        self::assertTrue($this->decode($replayed)['replayed'] ?? false);
        self::assertSame(1, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tenants WHERE slug = :slug',
            ['slug' => 'new-customer'],
        ));

        $keyReused = $this->createTenant(
            $login,
            $idempotencyKey,
            [...$payload, 'name' => 'Different customer'],
        );
        self::assertSame(409, $keyReused->getStatusCode());
        self::assertSame(
            'IDEMPOTENCY_KEY_REUSED',
            $this->decode($keyReused)['code'],
        );

        $accepted = $this->app->handle(
            $this->request('POST', '/api/v1/auth/invitations/accept')
                ->withParsedBody([
                    'token' => $this->ownerInvitationToken($tenantId),
                    'display_name' => 'First owner',
                    'preferred_locale' => 'sk',
                    'password' => 'a unique owner invitation passphrase',
                    'password_confirmation' => 'a unique owner invitation passphrase',
                ]),
        );

        self::assertSame(201, $accepted->getStatusCode());
        self::assertSame('ACTIVE', $this->connection->fetchOne(
            'SELECT status FROM tenants WHERE id = :tenant_id',
            ['tenant_id' => $tenantId],
        ));
        self::assertSame(1, $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM tenant_membership_role_assignments assignment
                INNER JOIN tenant_roles role
                    ON role.tenant_id = assignment.tenant_id
                    AND role.id = assignment.role_id
                WHERE assignment.tenant_id = :tenant_id
                    AND role.code = 'TENANT_OWNER'
                SQL,
            ['tenant_id' => $tenantId],
        ));
    }

    public function testLifecycleUsesRevisionReasonAuditAndDeletionGrace(): void
    {
        $login = $this->superadminLogin();
        $tenantId = $this->activeTenantWithOwner();
        $suspended = $this->changeStatus(
            $login,
            $tenantId,
            'SUSPENDED',
            1,
        );
        $suspendedTenant = $this->decode($suspended)['tenant'] ?? null;

        self::assertSame(200, $suspended->getStatusCode());
        self::assertIsArray($suspendedTenant);
        self::assertSame('SUSPENDED', $suspendedTenant['status'] ?? null);
        self::assertSame(2, $suspendedTenant['revision'] ?? null);

        $stale = $this->changeStatus(
            $login,
            $tenantId,
            'ACTIVE',
            1,
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('TENANT_REVISION_CONFLICT', $this->decode($stale)['code']);

        $archived = $this->changeStatus(
            $login,
            $tenantId,
            'ARCHIVED',
            2,
        );
        self::assertSame(200, $archived->getStatusCode());
        $deletionPending = $this->changeStatus(
            $login,
            $tenantId,
            'DELETION_PENDING',
            3,
        );
        $pendingTenant = $this->decode($deletionPending)['tenant'] ?? null;

        self::assertSame(200, $deletionPending->getStatusCode());
        self::assertIsArray($pendingTenant);
        self::assertIsString($pendingTenant['deletion_effective_at'] ?? null);
        self::assertGreaterThanOrEqual(
            30 * 86_400 - 10,
            strtotime($pendingTenant['deletion_effective_at']) - time(),
        );

        $cancelled = $this->changeStatus(
            $login,
            $tenantId,
            'ARCHIVED',
            4,
        );
        $cancelledTenant = $this->decode($cancelled)['tenant'] ?? null;
        self::assertSame(200, $cancelled->getStatusCode());
        self::assertIsArray($cancelledTenant);
        self::assertNull($cancelledTenant['deletion_effective_at'] ?? null);
        self::assertSame(4, $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM security_audit_events
                WHERE actor_user_id = :actor_user_id
                    AND tenant_id = :tenant_id
                    AND event_type = 'SYSTEM_TENANT_STATUS_CHANGED'
                SQL,
            [
                'actor_user_id' => $this->userId,
                'tenant_id' => $tenantId,
            ],
        ));
    }

    private function superadminLogin(): ResponseInterface
    {
        $this->connection->insert('user_system_roles', [
            'user_id' => $this->userId,
            'role_code' => 'SUPERADMIN',
        ]);

        return $this->login();
    }

    private function login(): ResponseInterface
    {
        $response = $this->app->handle(
            $this->request('POST', '/api/v1/auth/login')
                ->withParsedBody([
                    'email' => 'system-admin@example.test',
                    'password' => 'correct horse battery staple',
                ]),
        );
        self::assertSame(200, $response->getStatusCode());

        return $response;
    }

    /**
     * @param array<string, string> $payload
     */
    private function createTenant(
        ResponseInterface $login,
        string $idempotencyKey,
        array $payload,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                '/api/v1/system/tenants',
                $login,
            )
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->withParsedBody($payload),
        );
    }

    private function changeStatus(
        ResponseInterface $login,
        string $tenantId,
        string $status,
        int $revision,
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf('/api/v1/system/tenants/%s', $tenantId),
                $login,
            )->withParsedBody([
                'status' => $status,
                'revision' => $revision,
                'reason' => 'Lifecycle change confirmed by the system administrator.',
            ]),
        );
    }

    private function activeTenantWithOwner(): string
    {
        $tenantId = (string) UuidV7::generate();
        $membershipId = (string) UuidV7::generate();
        $this->connection->insert('tenants', [
            'id' => $tenantId,
            'name' => 'Lifecycle tenant',
            'slug' => sprintf('lifecycle-%s', substr($tenantId, 0, 8)),
            'status' => 'ACTIVE',
        ]);
        $provisioner = $this->app->getContainer()->get(
            TenantRoleProvisioner::class,
        );

        if (!$provisioner instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        $provisioner->provisionDefaults($tenantId, $this->userId);
        $this->connection->insert('tenant_memberships', [
            'id' => $membershipId,
            'tenant_id' => $tenantId,
            'user_id' => $this->userId,
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
                    AND code = 'TENANT_OWNER'
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'user_id' => $this->userId,
            ],
        );

        return $tenantId;
    }

    private function ownerInvitationToken(string $tenantId): string
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT sensitive.key_id, sensitive.ciphertext
                FROM outbox_sensitive_payloads sensitive
                INNER JOIN outbox_events event
                    ON event.id = sensitive.event_id
                WHERE event.tenant_id = :tenant_id
                    AND event.event_name = 'TENANT_INVITATION_DELIVERY_REQUESTED'
                ORDER BY event.created_at DESC
                LIMIT 1
                SQL,
            ['tenant_id' => $tenantId],
        );
        self::assertIsArray($row);
        $cipher = $this->app->getContainer()->get(
            SensitivePayloadCipher::class,
        );

        if (!$cipher instanceof SensitivePayloadCipher) {
            self::fail('The container must provide a sensitive payload cipher.');
        }

        $keyId = $row['key_id'] ?? null;
        $ciphertext = $row['ciphertext'] ?? null;
        self::assertIsString($keyId);
        self::assertIsString($ciphertext);
        $payload = $cipher->decrypt($keyId, $ciphertext);
        $token = $payload['token'] ?? null;
        self::assertIsString($token);

        return $token;
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
            ['REMOTE_ADDR' => '203.0.113.110'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $value = json_decode(
            $response->getBody()->__toString(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($value)) {
            self::fail('Expected a JSON object response.');
        }

        $payload = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected JSON object keys to be strings.');
            }

            $payload[$key] = $item;
        }

        return $payload;
    }

    private function cookieValue(
        ResponseInterface $response,
        string $cookieName,
    ): string {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, sprintf('%s=', $cookieName))) {
                return rawurldecode(substr(
                    explode(';', $header, 2)[0],
                    strlen($cookieName) + 1,
                ));
            }
        }

        self::fail(sprintf('Cookie "%s" was not set.', $cookieName));
    }
}
