<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Authorization\Domain\Permission;
use Sova\Authorization\Domain\PermissionCatalog;
use Sova\Authorization\Domain\PermissionScope;

final class AuthorizationCatalogTest extends TestCase
{
    public function testCatalogContainsStableMetadataAndValidDependencies(): void
    {
        $definitions = PermissionCatalog::all();

        self::assertCount(count(Permission::cases()), $definitions);

        foreach ($definitions as $definition) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/',
                $definition->permission->value,
            );
            self::assertNotSame('', trim($definition->label));
            self::assertNotSame('', trim($definition->description));

            foreach ($definition->dependencies as $dependency) {
                self::assertNotSame($definition->permission, $dependency);
                self::assertSame(
                    $definition->permission->scope(),
                    $dependency->scope(),
                );
            }
        }
    }

    /**
     * @param list<PermissionScope> $scopes
     */
    #[DataProvider('defaultRoleScopes')]
    public function testDefaultRoleMatrixIncludesPermissionDependencies(
        DefaultRole $role,
        array $scopes,
    ): void {
        foreach ($scopes as $scope) {
            $permissions = $role->permissions($scope);

            self::assertSame(
                count($permissions),
                count(array_unique($permissions, SORT_REGULAR)),
            );

            foreach ($permissions as $permission) {
                foreach ($permission->dependencies() as $dependency) {
                    self::assertContains(
                        $dependency,
                        $permissions,
                        sprintf(
                            '%s in %s scope must include dependency %s for %s.',
                            $role->value,
                            $scope->value,
                            $dependency->value,
                            $permission->value,
                        ),
                    );
                }
            }
        }
    }

    public function testSuperadminAlwaysContainsTheEntireCatalog(): void
    {
        self::assertSame(
            Permission::cases(),
            DefaultRole::Superadmin->permissions(PermissionScope::System),
        );
    }

    public function testRoleCannotBeEvaluatedInAnUnsupportedScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DefaultRole::ProjectManager->permissions(PermissionScope::Tenant);
    }

    /**
     * @return iterable<string, array{DefaultRole, list<PermissionScope>}>
     */
    public static function defaultRoleScopes(): iterable
    {
        foreach (DefaultRole::cases() as $role) {
            yield $role->value => [$role, $role->assignableScopes()];
        }
    }
}
