<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Persistence;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\EffectivePermissionProvider;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Authorization\Domain\Permission;
use Sova\Authorization\Domain\PermissionScope;
use Sova\Authorization\Infrastructure\Persistence\DoctrineEffectivePermissionProvider;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class DoctrineEffectivePermissionProviderTest extends TestCase
{
    private Connection $connection;
    private DoctrineEffectivePermissionProvider $permissions;
    private TenantRoleProvisioner $roles;
    private string $userId;
    private string $tenantId;
    private string $membershipId;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 3));
        $container = $app->getContainer();
        $connection = $container->get(Connection::class);
        $permissions = $container->get(EffectivePermissionProvider::class);
        $roles = $container->get(TenantRoleProvisioner::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        if (!$permissions instanceof DoctrineEffectivePermissionProvider) {
            self::fail(
                'The container must provide the Doctrine permission provider.',
            );
        }

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        $this->connection = $connection;
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->connection->beginTransaction();
        $this->userId = $this->insertUser();
        $this->tenantId = $this->insertTenant('Primary');
        $this->membershipId = $this->insertMembership(
            $this->tenantId,
            $this->userId,
        );
        $this->roles->provisionDefaults($this->tenantId, $this->userId);
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

    public function testProvisioningIsIdempotentAndMatchesTheDefaultMatrix(): void
    {
        $this->roles->provisionDefaults($this->tenantId, $this->userId);
        $expectedRoles = array_values(array_filter(
            DefaultRole::cases(),
            static fn(DefaultRole $role): bool => in_array(
                PermissionScope::Tenant,
                $role->assignableScopes(),
                true,
            ),
        ));

        self::assertCount(
            count($expectedRoles),
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id
                    FROM tenant_roles
                    WHERE tenant_id = :tenant_id
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );

        foreach ($expectedRoles as $role) {
            $stored = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT id, is_system, is_editable
                    FROM tenant_roles
                    WHERE tenant_id = :tenant_id
                        AND code = :code
                    SQL,
                [
                    'tenant_id' => $this->tenantId,
                    'code' => $role->value,
                ],
            );

            self::assertIsArray($stored);
            self::assertTrue($this->databaseBoolean(
                $stored['is_system'] ?? null,
            ));
            self::assertFalse($this->databaseBoolean(
                $stored['is_editable'] ?? null,
            ));
            $roleId = $stored['id'] ?? null;
            self::assertIsString($roleId);
            $actualPermissions = $this->connection->fetchFirstColumn(
                <<<'SQL'
                    SELECT permission_code
                    FROM tenant_role_permissions
                    WHERE tenant_id = :tenant_id
                        AND role_id = :role_id
                    ORDER BY permission_code
                    SQL,
                [
                    'tenant_id' => $this->tenantId,
                    'role_id' => $roleId,
                ],
            );
            $expectedPermissions = array_map(
                static fn(Permission $permission): string => $permission->value,
                $role->permissions(PermissionScope::Tenant),
            );
            sort($expectedPermissions);

            self::assertSame($expectedPermissions, $actualPermissions);
        }
    }

    public function testAssignmentGrantAndRevocationInvalidateCachedDecision(): void
    {
        $scope = AuthorizationScope::tenant($this->tenantId);

        self::assertFalse($this->permissions->hasPermission(
            $this->userId,
            Permission::TenantMembersInvite,
            $scope,
        ));

        $this->assignRole(DefaultRole::TenantAdmin);

        self::assertTrue($this->permissions->hasPermission(
            $this->userId,
            Permission::TenantMembersInvite,
            $scope,
        ));

        $this->connection->delete('tenant_membership_role_assignments', [
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->membershipId,
            'role_id' => $this->roleId(DefaultRole::TenantAdmin),
        ]);

        self::assertFalse($this->permissions->hasPermission(
            $this->userId,
            Permission::TenantMembersInvite,
            $scope,
        ));
    }

    public function testIdentityAndRoleStateChangesInvalidateCachedGrant(): void
    {
        $scope = AuthorizationScope::tenant($this->tenantId);
        $this->assignRole(DefaultRole::TenantAdmin);
        $assertGranted = function (bool $expected) use ($scope): void {
            self::assertSame(
                $expected,
                $this->permissions->hasPermission(
                    $this->userId,
                    Permission::TenantMembersInvite,
                    $scope,
                ),
            );
        };

        $assertGranted(true);
        $this->updateStatus('tenant_memberships', $this->membershipId, 'DISABLED');
        $assertGranted(false);
        $this->updateStatus('tenant_memberships', $this->membershipId, 'ACTIVE');
        $assertGranted(true);
        $this->updateStatus('users', $this->userId, 'DISABLED');
        $assertGranted(false);
        $this->updateStatus('users', $this->userId, 'ACTIVE');
        $assertGranted(true);
        $this->updateStatus('tenants', $this->tenantId, 'SUSPENDED');
        $assertGranted(false);
        $this->updateStatus('tenants', $this->tenantId, 'ACTIVE');
        $assertGranted(true);
        $this->updateStatus(
            'tenant_roles',
            $this->roleId(DefaultRole::TenantAdmin),
            'ARCHIVED',
        );
        $assertGranted(false);
    }

    public function testDatabaseRejectsCrossTenantRoleAssignment(): void
    {
        $foreignTenantId = $this->insertTenant('Foreign');
        $this->roles->provisionDefaults($foreignTenantId, $this->userId);
        $foreignRoleId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :code
                SQL,
            [
                'tenant_id' => $foreignTenantId,
                'code' => DefaultRole::TenantAdmin->value,
            ],
        );
        self::assertIsString($foreignRoleId);

        $this->expectException(DbalException::class);

        $this->connection->insert('tenant_membership_role_assignments', [
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->membershipId,
            'role_id' => $foreignRoleId,
            'granted_by_user_id' => $this->userId,
        ]);
    }

    private function insertUser(): string
    {
        $id = (string) UuidV7::generate();
        $email = sprintf('%s@example.test', str_replace('-', '', $id));

        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => 'test-password-hash',
            'display_name' => 'Authorization Test User',
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertTenant(string $name): string
    {
        $id = (string) UuidV7::generate();

        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => sprintf('%s Tenant', $name),
            'slug' => sprintf(
                '%s-%s',
                strtolower($name),
                substr(str_replace('-', '', $id), 0, 12),
            ),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertMembership(string $tenantId, string $userId): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('tenant_memberships', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function assignRole(DefaultRole $role): void
    {
        $this->connection->insert('tenant_membership_role_assignments', [
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->membershipId,
            'role_id' => $this->roleId($role),
            'granted_by_user_id' => $this->userId,
        ]);
    }

    private function roleId(DefaultRole $role): string
    {
        $roleId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :code
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'code' => $role->value,
            ],
        );
        self::assertIsString($roleId);

        return $roleId;
    }

    private function updateStatus(string $table, string $id, string $status): void
    {
        $this->connection->update($table, ['status' => $status], ['id' => $id]);
    }

    private function databaseBoolean(mixed $value): bool
    {
        if (in_array($value, [true, 1, '1', 't', 'true'], true)) {
            return true;
        }

        if (in_array($value, [false, 0, '0', 'f', 'false'], true)) {
            return false;
        }

        self::fail('Expected a database boolean value.');
    }
}
