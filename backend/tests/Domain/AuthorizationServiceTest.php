<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Application\EffectivePermissionProvider;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Domain\Error\DomainProblemException;

final class AuthorizationServiceTest extends TestCase
{
    public function testRegularUserIsDeniedByDefaultAndCanUseAnEffectiveGrant(): void
    {
        $provider = new InMemoryEffectivePermissionProvider([
            Permission::TenantMembersInvite,
        ]);
        $authorization = new AuthorizationService($provider);
        $subject = AuthorizationSubject::authenticated('member-id', false);
        $scope = AuthorizationScope::tenant('tenant-id');

        self::assertTrue($authorization->isGranted(
            $subject,
            Permission::TenantMembersInvite,
            $scope,
        ));
        self::assertFalse($authorization->isGranted(
            $subject,
            Permission::TenantSettingsManage,
            $scope,
        ));
        self::assertSame('member-id', $provider->lastUserId);
    }

    public function testGrantedPermissionsDropCodesTheScopeDoesNotSupport(): void
    {
        // A tenant role legitimately stores project-scoped codes; they must not
        // appear as tenant-scoped grants in the UI affordance list.
        $authorization = new AuthorizationService(
            new InMemoryEffectivePermissionProvider([
                Permission::TenantMembersInvite,
                Permission::ProjectView,
                Permission::IssueCreate,
            ]),
        );

        $granted = $authorization->grantedPermissions(
            AuthorizationSubject::authenticated('member-id', false),
            AuthorizationScope::tenant('tenant-id'),
        );

        self::assertSame([Permission::TenantMembersInvite], $granted);
    }

    public function testGrantedPermissionsGiveASuperadminEveryPermissionOfTheScope(): void
    {
        $authorization = new AuthorizationService(
            new InMemoryEffectivePermissionProvider([]),
        );

        $granted = $authorization->grantedPermissions(
            AuthorizationSubject::authenticated('superadmin-id', true),
            AuthorizationScope::tenant('tenant-id'),
        );

        self::assertNotSame([], $granted);
        self::assertContains(Permission::TenantMembersInvite, $granted);
        self::assertNotContains(Permission::ProjectView, $granted);
        self::assertNotContains(Permission::SystemTenantsView, $granted);
    }

    public function testImpersonationLosesTheSuperadminGrantList(): void
    {
        $authorization = new AuthorizationService(
            new InMemoryEffectivePermissionProvider([]),
        );

        $granted = $authorization->grantedPermissions(
            AuthorizationSubject::impersonated('superadmin-id', 'target-id', true),
            AuthorizationScope::tenant('tenant-id'),
        );

        self::assertSame([], $granted);
    }

    public function testSuperadminBypassStillRequiresTheCorrectExplicitScope(): void
    {
        $authorization = new AuthorizationService(
            new InMemoryEffectivePermissionProvider([]),
        );
        $subject = AuthorizationSubject::authenticated(
            'superadmin-id',
            true,
        );

        self::assertTrue($authorization->isGranted(
            $subject,
            Permission::TenantMembersInvite,
            AuthorizationScope::tenant('tenant-id'),
        ));
        self::assertFalse($authorization->isGranted(
            $subject,
            Permission::ProjectView,
            AuthorizationScope::tenant('tenant-id'),
        ));
    }

    public function testImpersonationUsesOnlyTheEffectiveUsersPermissions(): void
    {
        $provider = new InMemoryEffectivePermissionProvider([]);
        $authorization = new AuthorizationService($provider);
        $subject = AuthorizationSubject::impersonated(
            'superadmin-id',
            'effective-member-id',
            true,
        );

        self::assertFalse($authorization->isGranted(
            $subject,
            Permission::TenantMembersInvite,
            AuthorizationScope::tenant('tenant-id'),
        ));
        self::assertSame('effective-member-id', $provider->lastUserId);
    }

    public function testRequireReturnsTheStableGenericPermissionProblem(): void
    {
        $authorization = new AuthorizationService(
            new InMemoryEffectivePermissionProvider([]),
        );

        try {
            $authorization->require(
                AuthorizationSubject::authenticated('member-id', false),
                Permission::TenantMembersInvite,
                AuthorizationScope::tenant('tenant-id'),
            );
            self::fail('A missing permission must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame('PERMISSION_DENIED', $exception->problemCode());
            self::assertSame(
                'You do not have permission to perform this operation.',
                $exception->getMessage(),
            );
        }
    }
}

final class InMemoryEffectivePermissionProvider implements EffectivePermissionProvider
{
    public ?string $lastUserId = null;

    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        private readonly array $permissions,
    ) {}

    public function hasPermission(
        string $userId,
        Permission $permission,
        AuthorizationScope $scope,
    ): bool {
        $this->lastUserId = $userId;

        return in_array($permission, $this->permissions, true);
    }

    public function listPermissions(string $userId, AuthorizationScope $scope): array
    {
        $this->lastUserId = $userId;

        return $this->permissions;
    }
}
