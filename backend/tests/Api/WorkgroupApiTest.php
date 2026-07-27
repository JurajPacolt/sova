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

final class WorkgroupApiTest extends TestCase
{
    private const PASSWORD = 'A unique workgroup administration passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $ownerId;
    private string $memberId;
    private string $memberMembershipId;
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
        $this->ownerId = $this->insertUser('workgroup-owner');
        $this->memberId = $this->insertUser('workgroup-member');
        $this->tenantId = $this->insertTenant('workgroup-primary');
        $this->foreignTenantId = $this->insertTenant('workgroup-foreign');
        $roles->provisionDefaults($this->tenantId, $this->ownerId);
        $this->addMembership($this->tenantId, $this->ownerId, DefaultRole::TenantOwner);
        $this->memberMembershipId = $this->addMembership(
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

    public function testWorkgroupEndpointsRejectAPlainMember(): void
    {
        $login = $this->login('workgroup-member');
        $response = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf('/api/v1/tenants/%s/workgroups', $this->tenantId),
                $login,
            ),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PERMISSION_DENIED', $this->decode($response)['code']);
    }

    public function testTenantAdminCreatesListsAndArchivesAWorkgroup(): void
    {
        $login = $this->login('workgroup-owner');
        $create = $this->createWorkgroup($login, 'Platform team', 'Core platform.');
        self::assertSame(201, $create->getStatusCode());
        $workgroup = $this->decode($create)['workgroup'] ?? null;
        self::assertIsArray($workgroup);
        $workgroupId = $workgroup['id'] ?? null;
        self::assertIsString($workgroupId);
        self::assertSame('ACTIVE', $workgroup['status'] ?? null);
        self::assertSame(0, $workgroup['member_count'] ?? null);

        $list = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf('/api/v1/tenants/%s/workgroups', $this->tenantId),
                $login,
            ),
        );
        $workgroups = $this->decode($list)['workgroups'] ?? null;
        self::assertIsArray($workgroups);
        self::assertCount(1, $workgroups);

        $archive = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf('/api/v1/tenants/%s/workgroups/%s', $this->tenantId, $workgroupId),
                $login,
            )->withParsedBody(['status' => 'ARCHIVED']),
        );
        self::assertSame(200, $archive->getStatusCode());
        $archived = $this->decode($archive)['workgroup'] ?? null;
        self::assertIsArray($archived);
        self::assertSame('ARCHIVED', $archived['status'] ?? null);

        $invalidTransition = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf('/api/v1/tenants/%s/workgroups/%s', $this->tenantId, $workgroupId),
                $login,
            )->withParsedBody(['status' => 'ARCHIVED']),
        );
        self::assertSame(200, $invalidTransition->getStatusCode());
    }

    public function testMemberAddedAsManagerCanManageTheirOwnWorkgroupWithoutTenantPermission(): void
    {
        $ownerLogin = $this->login('workgroup-owner');
        $workgroupId = $this->createWorkgroupId($ownerLogin, 'Design guild');
        $addManager = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/workgroups/%s/members/%s',
                    $this->tenantId,
                    $workgroupId,
                    $this->memberMembershipId,
                ),
                $ownerLogin,
            )->withParsedBody(['role' => 'MANAGER']),
        );
        self::assertSame(200, $addManager->getStatusCode());
        $addedMember = $this->decode($addManager)['member'] ?? null;
        self::assertIsArray($addedMember);
        self::assertSame('MANAGER', $addedMember['role'] ?? null);

        $memberLogin = $this->login('workgroup-member');
        $rename = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf('/api/v1/tenants/%s/workgroups/%s', $this->tenantId, $workgroupId),
                $memberLogin,
            )->withParsedBody(['status' => 'ARCHIVED']),
        );

        self::assertSame(200, $rename->getStatusCode());

        $secondMemberId = $this->insertUser('workgroup-second');
        $secondMembershipId = $this->addMembership(
            $this->tenantId,
            $secondMemberId,
            DefaultRole::Member,
        );
        $addSecond = $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/workgroups/%s/members/%s',
                    $this->tenantId,
                    $workgroupId,
                    $secondMembershipId,
                ),
                $memberLogin,
            )->withParsedBody(['role' => 'MEMBER']),
        );
        self::assertSame(200, $addSecond->getStatusCode());

        $members = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf('/api/v1/tenants/%s/workgroups/%s/members', $this->tenantId, $workgroupId),
                $memberLogin,
            ),
        );
        $memberList = $this->decode($members)['members'] ?? null;
        self::assertIsArray($memberList);
        self::assertCount(2, $memberList);

        $remove = $this->app->handle(
            $this->authenticatedRequest(
                'DELETE',
                sprintf(
                    '/api/v1/tenants/%s/workgroups/%s/members/%s',
                    $this->tenantId,
                    $workgroupId,
                    $secondMembershipId,
                ),
                $memberLogin,
            ),
        );
        self::assertSame(204, $remove->getStatusCode());
    }

    public function testPlainWorkgroupMemberCannotManageItsSettingsOrMembers(): void
    {
        $ownerLogin = $this->login('workgroup-owner');
        $workgroupId = $this->createWorkgroupId($ownerLogin, 'Support crew');
        $this->app->handle(
            $this->authenticatedRequest(
                'PUT',
                sprintf(
                    '/api/v1/tenants/%s/workgroups/%s/members/%s',
                    $this->tenantId,
                    $workgroupId,
                    $this->memberMembershipId,
                ),
                $ownerLogin,
            )->withParsedBody(['role' => 'MEMBER']),
        );

        $memberLogin = $this->login('workgroup-member');
        $denied = $this->app->handle(
            $this->authenticatedRequest(
                'PATCH',
                sprintf('/api/v1/tenants/%s/workgroups/%s', $this->tenantId, $workgroupId),
                $memberLogin,
            )->withParsedBody(['status' => 'ARCHIVED']),
        );

        self::assertSame(403, $denied->getStatusCode());
    }

    public function testWorkgroupsAreIsolatedPerTenant(): void
    {
        $ownerLogin = $this->login('workgroup-owner');
        $workgroupId = $this->createWorkgroupId($ownerLogin, 'Cross tenant test');

        $foreignOwnerId = $this->insertUser('workgroup-foreign-owner');
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $this->foreignTenantId,
            'user_id' => $foreignOwnerId,
            'status' => 'ACTIVE',
        ]);
        $foreignLogin = $this->login('workgroup-foreign-owner');
        $crossTenantAccess = $this->app->handle(
            $this->authenticatedRequest(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/workgroups/%s/members',
                    $this->foreignTenantId,
                    $workgroupId,
                ),
                $foreignLogin,
            ),
        );

        self::assertContains($crossTenantAccess->getStatusCode(), [403, 404]);
    }

    private function createWorkgroup(
        ResponseInterface $login,
        string $name,
        string $description = '',
    ): ResponseInterface {
        return $this->app->handle(
            $this->authenticatedRequest(
                'POST',
                sprintf('/api/v1/tenants/%s/workgroups', $this->tenantId),
                $login,
            )->withParsedBody(['name' => $name, 'description' => $description]),
        );
    }

    private function createWorkgroupId(ResponseInterface $login, string $name): string
    {
        $response = $this->createWorkgroup($login, $name);
        self::assertSame(201, $response->getStatusCode());
        $workgroup = $this->decode($response)['workgroup'] ?? null;
        self::assertIsArray($workgroup);
        $id = $workgroup['id'] ?? null;
        self::assertIsString($id);

        return $id;
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
            ->withHeader(
                'X-CSRF-Token',
                $this->cookieValue($login, 'sova_csrf'),
            );
    }

    private function request(
        string $method,
        string $uri,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
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
}
