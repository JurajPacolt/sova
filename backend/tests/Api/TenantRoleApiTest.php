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
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\EffectivePermissionProvider;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Infrastructure\Security\Argon2idPasswordHasher;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class TenantRoleApiTest extends TestCase
{
    private const PASSWORD = 'A unique tenant role API passphrase';

    /**
     * @var App<Container>
     */
    private App $app;
    private Connection $connection;
    private string $tenantId;
    private string $ownerUserId;
    private string $ownerMembershipId;
    private string $adminUserId;
    private string $adminMembershipId;
    private string $memberUserId;
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
        $container = $app->getContainer();
        $connection = $container->get(Connection::class);
        $roles = $container->get(TenantRoleProvisioner::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a tenant role provisioner.');
        }

        $this->app = $app;
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->ownerUserId = $this->insertUser('owner');
        $this->adminUserId = $this->insertUser('admin');
        $this->memberUserId = $this->insertUser('member');
        $this->tenantId = $this->insertTenant('primary');
        $this->ownerMembershipId = $this->insertMembership(
            $this->ownerUserId,
        );
        $this->adminMembershipId = $this->insertMembership(
            $this->adminUserId,
        );
        $this->memberMembershipId = $this->insertMembership(
            $this->memberUserId,
        );
        $roles->provisionDefaults($this->tenantId, $this->ownerUserId);
        $this->assignDirectly(
            $this->ownerMembershipId,
            DefaultRole::TenantOwner,
        );
        $this->assignDirectly(
            $this->adminMembershipId,
            DefaultRole::TenantAdmin,
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

    public function testOwnerCanListRolesAndNonSystemPermissionCatalog(): void
    {
        $response = $this->listRoles($this->login('owner'));
        $payload = $this->decode($response);
        $roles = $payload['roles'] ?? null;
        $permissions = $payload['permissions'] ?? null;

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($roles);
        self::assertCount(4, $roles);
        self::assertIsArray($permissions);
        self::assertCount(32, $permissions);

        foreach ($permissions as $permission) {
            self::assertIsArray($permission);
            $code = $permission['code'] ?? null;
            self::assertIsString($code);
            self::assertFalse(str_starts_with($code, 'system.'));
            self::assertIsBool($permission['sensitive'] ?? null);
            self::assertIsArray($permission['dependencies'] ?? null);
        }
    }

    public function testMemberWithoutRoleCannotListRoles(): void
    {
        $response = $this->listRoles($this->login('member'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'PERMISSION_DENIED',
            $this->decode($response)['code'],
        );
    }

    public function testOwnerCanListMembershipsWithAssignedRoles(): void
    {
        $response = $this->listMemberships($this->login('owner'));
        $memberships = $this->decode($response)['memberships'] ?? null;

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($memberships);
        self::assertCount(3, $memberships);
        $byId = [];

        foreach ($memberships as $membership) {
            self::assertIsArray($membership);
            $id = $membership['id'] ?? null;
            self::assertIsString($id);
            $byId[$id] = $membership;
            self::assertIsArray($membership['user'] ?? null);
            self::assertIsString(
                $membership['user']['email'] ?? null,
            );
            self::assertIsString($membership['joined_at'] ?? null);
            self::assertIsArray($membership['roles'] ?? null);
        }

        $owner = $byId[$this->ownerMembershipId] ?? null;
        $administrator = $byId[$this->adminMembershipId] ?? null;
        $member = $byId[$this->memberMembershipId] ?? null;
        self::assertIsArray($owner);
        self::assertIsArray($administrator);
        self::assertIsArray($member);
        self::assertSame('ACTIVE', $owner['status'] ?? null);
        $ownerRoles = $owner['roles'] ?? null;
        $administratorRoles = $administrator['roles'] ?? null;
        self::assertIsArray($ownerRoles);
        self::assertIsArray($administratorRoles);
        $ownerRole = $ownerRoles[0] ?? null;
        $administratorRole = $administratorRoles[0] ?? null;
        self::assertIsArray($ownerRole);
        self::assertIsArray($administratorRole);
        self::assertSame(
            DefaultRole::TenantOwner->value,
            $ownerRole['code'] ?? null,
        );
        self::assertSame(
            DefaultRole::TenantAdmin->value,
            $administratorRole['code'] ?? null,
        );
        self::assertSame([], $member['roles'] ?? null);
    }

    public function testMembershipLifecycleInvalidatesAccessAndIsAudited(): void
    {
        $this->assignDirectly(
            $this->memberMembershipId,
            DefaultRole::Member,
        );
        $administrator = $this->login('admin');
        $member = $this->login('member');
        $provider = $this->app->getContainer()->get(
            EffectivePermissionProvider::class,
        );
        $scope = AuthorizationScope::tenant($this->tenantId);

        if (!$provider instanceof EffectivePermissionProvider) {
            self::fail('The container must provide a permission provider.');
        }

        self::assertTrue($provider->hasPermission(
            $this->memberUserId,
            Permission::TenantView,
            $scope,
        ));
        self::assertSame(
            200,
            $this->changeMembershipStatus(
                $administrator,
                $this->memberMembershipId,
                'DISABLED',
            )->getStatusCode(),
        );
        self::assertFalse($provider->hasPermission(
            $this->memberUserId,
            Permission::TenantView,
            $scope,
        ));
        $blockedContext = $this->app->handle(
            $this->request(
                'GET',
                sprintf('/api/v1/tenants/%s', $this->tenantId),
            )->withCookieParams([
                'sova_session' => $this->cookieValue(
                    $member,
                    'sova_session',
                ),
            ]),
        );
        self::assertSame(404, $blockedContext->getStatusCode());

        self::assertSame(
            200,
            $this->changeMembershipStatus(
                $administrator,
                $this->memberMembershipId,
                'DISABLED',
            )->getStatusCode(),
        );
        $reactivated = $this->changeMembershipStatus(
            $administrator,
            $this->memberMembershipId,
            'ACTIVE',
        );
        self::assertSame(200, $reactivated->getStatusCode());
        $reactivatedMembership = $this->decode(
            $reactivated,
        )['membership'] ?? null;
        self::assertIsArray($reactivatedMembership);
        self::assertSame(
            'ACTIVE',
            $reactivatedMembership['status'] ?? null,
        );
        self::assertTrue($provider->hasPermission(
            $this->memberUserId,
            Permission::TenantView,
            $scope,
        ));

        self::assertSame(
            200,
            $this->changeMembershipStatus(
                $administrator,
                $this->memberMembershipId,
                'DISABLED',
            )->getStatusCode(),
        );
        self::assertSame(
            200,
            $this->changeMembershipStatus(
                $administrator,
                $this->memberMembershipId,
                'REMOVED',
            )->getStatusCode(),
        );
        $terminal = $this->changeMembershipStatus(
            $administrator,
            $this->memberMembershipId,
            'ACTIVE',
        );

        self::assertSame(409, $terminal->getStatusCode());
        self::assertSame(
            'TENANT_MEMBERSHIP_TRANSITION_INVALID',
            $this->decode($terminal)['code'],
        );
        self::assertSame(
            4,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE tenant_id = :tenant_id
                        AND event_type IN (
                            'TENANT_MEMBERSHIP_DISABLED',
                            'TENANT_MEMBERSHIP_REACTIVATED',
                            'TENANT_MEMBERSHIP_REMOVED'
                        )
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
    }

    public function testMembershipLifecycleProtectsSelfAndOwnerPrivileges(): void
    {
        $owner = $this->login('owner');
        $self = $this->changeMembershipStatus(
            $owner,
            $this->ownerMembershipId,
            'DISABLED',
        );

        self::assertSame(409, $self->getStatusCode());
        self::assertSame(
            'TENANT_MEMBERSHIP_SELF_MANAGEMENT_FORBIDDEN',
            $this->decode($self)['code'],
        );

        self::assertSame(
            204,
            $this->mutateRole(
                'PUT',
                $owner,
                $this->memberMembershipId,
                $this->roleId(DefaultRole::TenantOwner),
            )->getStatusCode(),
        );
        $administrator = $this->changeMembershipStatus(
            $this->login('admin'),
            $this->memberMembershipId,
            'DISABLED',
        );

        self::assertSame(403, $administrator->getStatusCode());
        self::assertSame(
            'PERMISSION_DENIED',
            $this->decode($administrator)['code'],
        );
    }

    public function testLastOwnerGuardIsSharedWithMembershipLifecycle(): void
    {
        $superadminUserId = $this->insertUser('super');
        $this->connection->insert('user_system_roles', [
            'user_id' => $superadminUserId,
            'role_code' => 'SUPERADMIN',
        ]);
        $superadmin = $this->login('super');
        $lastOwner = $this->changeMembershipStatus(
            $superadmin,
            $this->ownerMembershipId,
            'DISABLED',
        );

        self::assertSame(409, $lastOwner->getStatusCode());
        self::assertSame(
            'TENANT_LAST_OWNER_REQUIRED',
            $this->decode($lastOwner)['code'],
        );

        self::assertSame(
            204,
            $this->mutateRole(
                'PUT',
                $this->login('owner'),
                $this->memberMembershipId,
                $this->roleId(DefaultRole::TenantOwner),
            )->getStatusCode(),
        );
        self::assertSame(
            200,
            $this->changeMembershipStatus(
                $superadmin,
                $this->ownerMembershipId,
                'DISABLED',
            )->getStatusCode(),
        );
        $newLastOwner = $this->changeMembershipStatus(
            $superadmin,
            $this->memberMembershipId,
            'DISABLED',
        );

        self::assertSame(409, $newLastOwner->getStatusCode());
        self::assertSame(
            'TENANT_LAST_OWNER_REQUIRED',
            $this->decode($newLastOwner)['code'],
        );
    }

    public function testMembershipLifecycleValidatesInputAndTenantBoundary(): void
    {
        $login = $this->login('owner');
        $invalid = $this->changeMembershipStatus(
            $login,
            $this->memberMembershipId,
            'UNKNOWN',
        );

        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'TENANT_MEMBERSHIP_INPUT_INVALID',
            $this->decode($invalid)['code'],
        );

        $foreignUserId = $this->insertUser('foreign-lifecycle');
        $foreignTenantId = $this->insertTenant('foreign-lifecycle');
        $foreignMembershipId = $this->insertMembership(
            $foreignUserId,
            $foreignTenantId,
        );
        $foreign = $this->changeMembershipStatus(
            $login,
            $foreignMembershipId,
            'DISABLED',
        );

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame(
            'TENANT_MEMBERSHIP_NOT_FOUND',
            $this->decode($foreign)['code'],
        );
    }

    public function testAdministratorCanAssignAndRevokeOrdinaryRole(): void
    {
        $login = $this->login('admin');
        $memberRoleId = $this->roleId(DefaultRole::Member);
        $scope = AuthorizationScope::tenant($this->tenantId);
        $provider = $this->app->getContainer()->get(
            EffectivePermissionProvider::class,
        );

        if (!$provider instanceof EffectivePermissionProvider) {
            self::fail('The container must provide a permission provider.');
        }

        self::assertFalse($provider->hasPermission(
            $this->memberUserId,
            Permission::TenantView,
            $scope,
        ));
        self::assertSame(
            204,
            $this->mutateRole(
                'PUT',
                $login,
                $this->memberMembershipId,
                $memberRoleId,
            )->getStatusCode(),
        );
        self::assertTrue($provider->hasPermission(
            $this->memberUserId,
            Permission::TenantView,
            $scope,
        ));
        self::assertSame(
            204,
            $this->mutateRole(
                'PUT',
                $login,
                $this->memberMembershipId,
                $memberRoleId,
            )->getStatusCode(),
        );
        self::assertSame(1, $this->assignmentCount(
            $this->memberMembershipId,
            $memberRoleId,
        ));
        self::assertSame(
            204,
            $this->mutateRole(
                'DELETE',
                $login,
                $this->memberMembershipId,
                $memberRoleId,
            )->getStatusCode(),
        );
        self::assertFalse($provider->hasPermission(
            $this->memberUserId,
            Permission::TenantView,
            $scope,
        ));
        self::assertSame(
            2,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE tenant_id = :tenant_id
                        AND event_type IN (
                            'TENANT_ROLE_ASSIGNED',
                            'TENANT_ROLE_UNASSIGNED'
                        )
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
    }

    public function testAdministratorCannotGrantTenantOwnerRole(): void
    {
        $response = $this->mutateRole(
            'PUT',
            $this->login('admin'),
            $this->memberMembershipId,
            $this->roleId(DefaultRole::TenantOwner),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'PERMISSION_DENIED',
            $this->decode($response)['code'],
        );
    }

    public function testOwnerCanCreateAndUpdateCustomRole(): void
    {
        $login = $this->login('owner');
        $created = $this->createRoleDefinition(
            $login,
            $this->customRolePayload(),
        );
        $createdPayload = $this->decode($created);
        $role = $createdPayload['role'] ?? null;

        self::assertSame(201, $created->getStatusCode());
        self::assertIsArray($role);
        self::assertSame('SUPPORT_AGENT', $role['code'] ?? null);
        self::assertSame(1, $role['revision'] ?? null);
        self::assertFalse($role['is_system'] ?? true);
        self::assertTrue($role['is_editable'] ?? false);
        $roleId = $role['id'] ?? null;
        self::assertIsString($roleId);

        $updated = $this->updateRoleDefinition(
            $login,
            $roleId,
            [
                'name' => 'Senior support agent',
                'description' => 'Can invite tenant members.',
                'permissions' => [
                    Permission::TenantView->value,
                    Permission::TenantMembersView->value,
                    Permission::TenantMembersInvite->value,
                ],
                'revision' => 1,
            ],
        );
        $updatedRole = $this->decode($updated)['role'] ?? null;

        self::assertSame(200, $updated->getStatusCode());
        self::assertIsArray($updatedRole);
        self::assertSame('SUPPORT_AGENT', $updatedRole['code'] ?? null);
        self::assertSame(
            'Senior support agent',
            $updatedRole['name'] ?? null,
        );
        self::assertSame(2, $updatedRole['revision'] ?? null);

        $stale = $this->updateRoleDefinition(
            $login,
            $roleId,
            [
                'name' => 'Stale update',
                'description' => '',
                'permissions' => [],
                'revision' => 1,
            ],
        );

        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_REVISION_CONFLICT',
            $this->decode($stale)['code'],
        );
        self::assertSame(
            2,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE tenant_id = :tenant_id
                        AND event_type IN (
                            'TENANT_ROLE_CREATED',
                            'TENANT_ROLE_UPDATED'
                        )
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
    }

    public function testRoleCreationEnforcesAuthorizationAndValidation(): void
    {
        $administrator = $this->createRoleDefinition(
            $this->login('admin'),
            $this->customRolePayload(),
        );

        self::assertSame(403, $administrator->getStatusCode());
        self::assertSame(
            'PERMISSION_DENIED',
            $this->decode($administrator)['code'],
        );

        $owner = $this->login('owner');
        $invalid = $this->createRoleDefinition($owner, [
            'code' => 'SYSTEM_AUDITOR',
            'name' => 'System auditor',
            'description' => '',
            'permissions' => [
                Permission::SystemAuditView->value,
                Permission::TenantMembersInvite->value,
            ],
        ]);

        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_INPUT_INVALID',
            $this->decode($invalid)['code'],
        );
        self::assertSame(
            0,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM tenant_roles
                    WHERE tenant_id = :tenant_id
                        AND code = 'SYSTEM_AUDITOR'
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );

        self::assertSame(
            201,
            $this->createRoleDefinition(
                $owner,
                $this->customRolePayload(),
            )->getStatusCode(),
        );
        $duplicate = $this->createRoleDefinition(
            $owner,
            $this->customRolePayload(),
        );

        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_CODE_CONFLICT',
            $this->decode($duplicate)['code'],
        );
    }

    public function testAssignedRoleMustBeUnassignedBeforeArchive(): void
    {
        $login = $this->login('owner');
        $created = $this->decode($this->createRoleDefinition(
            $login,
            $this->customRolePayload(),
        ));
        $role = $created['role'] ?? null;
        self::assertIsArray($role);
        $roleId = $role['id'] ?? null;
        self::assertIsString($roleId);

        self::assertSame(
            204,
            $this->mutateRole(
                'PUT',
                $login,
                $this->memberMembershipId,
                $roleId,
            )->getStatusCode(),
        );
        $assigned = $this->archiveRoleDefinition($login, $roleId);

        self::assertSame(409, $assigned->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_ASSIGNED',
            $this->decode($assigned)['code'],
        );

        self::assertSame(
            204,
            $this->mutateRole(
                'DELETE',
                $login,
                $this->memberMembershipId,
                $roleId,
            )->getStatusCode(),
        );
        self::assertSame(
            204,
            $this->archiveRoleDefinition($login, $roleId)->getStatusCode(),
        );
        self::assertSame(
            204,
            $this->archiveRoleDefinition($login, $roleId)->getStatusCode(),
        );
        $reassign = $this->mutateRole(
            'PUT',
            $login,
            $this->memberMembershipId,
            $roleId,
        );

        self::assertSame(409, $reassign->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_INACTIVE',
            $this->decode($reassign)['code'],
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM security_audit_events
                    WHERE tenant_id = :tenant_id
                        AND event_type = 'TENANT_ROLE_ARCHIVED'
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
    }

    public function testSystemRoleDefinitionsAreImmutable(): void
    {
        $login = $this->login('owner');
        $roleId = $this->roleId(DefaultRole::Member);
        $update = $this->updateRoleDefinition($login, $roleId, [
            'name' => 'Changed member',
            'description' => '',
            'permissions' => [],
            'revision' => 1,
        ]);
        $archive = $this->archiveRoleDefinition($login, $roleId);

        self::assertSame(409, $update->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_IMMUTABLE',
            $this->decode($update)['code'],
        );
        self::assertSame(409, $archive->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_IMMUTABLE',
            $this->decode($archive)['code'],
        );
    }

    public function testForeignRoleDefinitionIsHiddenByTenantBoundary(): void
    {
        $foreignTenantId = $this->insertTenant('foreign-role');
        $roles = $this->app->getContainer()->get(
            TenantRoleProvisioner::class,
        );

        if (!$roles instanceof TenantRoleProvisioner) {
            self::fail('The container must provide a role provisioner.');
        }

        $roles->provisionDefaults($foreignTenantId, $this->ownerUserId);
        $foreignRoleId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = 'MEMBER'
                SQL,
            ['tenant_id' => $foreignTenantId],
        );
        self::assertIsString($foreignRoleId);

        $response = $this->updateRoleDefinition(
            $this->login('owner'),
            $foreignRoleId,
            [
                'name' => 'Foreign role',
                'description' => '',
                'permissions' => [],
                'revision' => 1,
            ],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'TENANT_ROLE_NOT_FOUND',
            $this->decode($response)['code'],
        );
    }

    public function testLastOwnerCannotBeRemovedUntilAnotherOwnerExists(): void
    {
        $login = $this->login('owner');
        $ownerRoleId = $this->roleId(DefaultRole::TenantOwner);
        $blocked = $this->mutateRole(
            'DELETE',
            $login,
            $this->ownerMembershipId,
            $ownerRoleId,
        );

        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame(
            'TENANT_LAST_OWNER_REQUIRED',
            $this->decode($blocked)['code'],
        );
        self::assertSame(
            204,
            $this->mutateRole(
                'PUT',
                $login,
                $this->memberMembershipId,
                $ownerRoleId,
            )->getStatusCode(),
        );
        self::assertSame(
            204,
            $this->mutateRole(
                'DELETE',
                $login,
                $this->ownerMembershipId,
                $ownerRoleId,
            )->getStatusCode(),
        );
        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM tenant_membership_role_assignments assignment
                    INNER JOIN tenant_roles role
                        ON role.tenant_id = assignment.tenant_id
                        AND role.id = assignment.role_id
                    INNER JOIN tenant_memberships membership
                        ON membership.tenant_id = assignment.tenant_id
                        AND membership.id = assignment.membership_id
                    WHERE assignment.tenant_id = :tenant_id
                        AND role.code = 'TENANT_OWNER'
                        AND membership.status = 'ACTIVE'
                    SQL,
                ['tenant_id' => $this->tenantId],
            ),
        );
    }

    public function testForeignMembershipIsHiddenByTenantBoundary(): void
    {
        $foreignUserId = $this->insertUser('foreign');
        $foreignTenantId = $this->insertTenant('foreign');
        $foreignMembershipId = $this->insertMembership(
            $foreignUserId,
            $foreignTenantId,
        );
        $response = $this->mutateRole(
            'PUT',
            $this->login('owner'),
            $foreignMembershipId,
            $this->roleId(DefaultRole::Member),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'TENANT_MEMBERSHIP_NOT_FOUND',
            $this->decode($response)['code'],
        );
    }

    private function insertUser(string $prefix): string
    {
        $id = (string) UuidV7::generate();
        $email = $this->email($prefix);
        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => (new Argon2idPasswordHasher())->hash(
                self::PASSWORD,
            ),
            'display_name' => sprintf('%s Role User', ucfirst($prefix)),
            'preferred_locale' => 'sk',
            'status' => 'ACTIVE',
            'email_verified_at' => '2026-07-26 00:00:00+00',
        ]);

        return $id;
    }

    private function email(string $prefix): string
    {
        return sprintf('%s-role-api@example.test', $prefix);
    }

    private function insertTenant(string $prefix): string
    {
        $id = (string) UuidV7::generate();
        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => sprintf('%s Role Tenant', ucfirst($prefix)),
            'slug' => sprintf(
                '%s-role-%s',
                $prefix,
                substr(str_replace('-', '', $id), 0, 8),
            ),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertMembership(
        string $userId,
        ?string $tenantId = null,
    ): string {
        $id = (string) UuidV7::generate();
        $this->connection->insert('tenant_memberships', [
            'id' => $id,
            'tenant_id' => $tenantId ?? $this->tenantId,
            'user_id' => $userId,
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function assignDirectly(
        string $membershipId,
        DefaultRole $role,
    ): void {
        $this->connection->insert('tenant_membership_role_assignments', [
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'role_id' => $this->roleId($role),
            'granted_by_user_id' => $this->ownerUserId,
        ]);
    }

    private function roleId(DefaultRole $role): string
    {
        $id = $this->connection->fetchOne(
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
        self::assertIsString($id);

        return $id;
    }

    private function assignmentCount(
        string $membershipId,
        string $roleId,
    ): int {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM tenant_membership_role_assignments
                WHERE tenant_id = :tenant_id
                    AND membership_id = :membership_id
                    AND role_id = :role_id
                SQL,
            [
                'tenant_id' => $this->tenantId,
                'membership_id' => $membershipId,
                'role_id' => $roleId,
            ],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function login(string $prefix): ResponseInterface
    {
        return $this->app->handle(
            $this->request('POST', '/api/v1/auth/login')->withParsedBody([
                'email' => $this->email($prefix),
                'password' => self::PASSWORD,
            ]),
        );
    }

    private function listRoles(ResponseInterface $login): ResponseInterface
    {
        return $this->app->handle(
            $this->request(
                'GET',
                sprintf('/api/v1/tenants/%s/roles', $this->tenantId),
            )->withCookieParams([
                'sova_session' => $this->cookieValue(
                    $login,
                    'sova_session',
                ),
            ]),
        );
    }

    private function listMemberships(
        ResponseInterface $login,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request(
                'GET',
                sprintf(
                    '/api/v1/tenants/%s/memberships',
                    $this->tenantId,
                ),
            )->withCookieParams([
                'sova_session' => $this->cookieValue(
                    $login,
                    'sova_session',
                ),
            ]),
        );
    }

    private function changeMembershipStatus(
        ResponseInterface $login,
        string $membershipId,
        string $status,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request(
                'PATCH',
                sprintf(
                    '/api/v1/tenants/%s/memberships/%s',
                    $this->tenantId,
                    $membershipId,
                ),
            )
                ->withCookieParams([
                    'sova_session' => $this->cookieValue(
                        $login,
                        'sova_session',
                    ),
                ])
                ->withHeader(
                    'X-CSRF-Token',
                    $this->cookieValue($login, 'sova_csrf'),
                )
                ->withParsedBody(['status' => $status]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createRoleDefinition(
        ResponseInterface $login,
        array $payload,
    ): ResponseInterface {
        return $this->roleDefinitionRequest(
            'POST',
            $login,
            null,
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateRoleDefinition(
        ResponseInterface $login,
        string $roleId,
        array $payload,
    ): ResponseInterface {
        return $this->roleDefinitionRequest(
            'PUT',
            $login,
            $roleId,
            $payload,
        );
    }

    private function archiveRoleDefinition(
        ResponseInterface $login,
        string $roleId,
    ): ResponseInterface {
        return $this->roleDefinitionRequest(
            'DELETE',
            $login,
            $roleId,
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function roleDefinitionRequest(
        string $method,
        ResponseInterface $login,
        ?string $roleId,
        ?array $payload = null,
    ): ResponseInterface {
        $uri = sprintf(
            '/api/v1/tenants/%s/roles',
            $this->tenantId,
        );

        if ($roleId !== null) {
            $uri .= sprintf('/%s', $roleId);
        }

        $request = $this->request($method, $uri)
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

        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function customRolePayload(): array
    {
        return [
            'code' => 'SUPPORT_AGENT',
            'name' => 'Support agent',
            'description' => 'Can view tenant members.',
            'permissions' => [
                Permission::TenantView->value,
                Permission::TenantMembersView->value,
            ],
        ];
    }

    private function mutateRole(
        string $method,
        ResponseInterface $login,
        string $membershipId,
        string $roleId,
    ): ResponseInterface {
        return $this->app->handle(
            $this->request(
                $method,
                sprintf(
                    '/api/v1/tenants/%s/memberships/%s/roles/%s',
                    $this->tenantId,
                    $membershipId,
                    $roleId,
                ),
            )
                ->withCookieParams([
                    'sova_session' => $this->cookieValue(
                        $login,
                        'sova_session',
                    ),
                ])
                ->withHeader(
                    'X-CSRF-Token',
                    $this->cookieValue($login, 'sova_csrf'),
                ),
        );
    }

    private function request(
        string $method,
        string $uri,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $uri,
            ['REMOTE_ADDR' => '203.0.113.121'],
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
