<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Authorization\Application\TenantRoleDefinitionValidator;
use Sova\Authorization\Domain\Permission;
use Sova\Shared\Domain\Error\DomainProblemException;

final class TenantRoleDefinitionValidatorTest extends TestCase
{
    public function testCreateNormalizesInputAndUsesCatalogOrder(): void
    {
        $input = (new TenantRoleDefinitionValidator())->forCreate([
            'code' => '  SUPPORT_AGENT  ',
            'name' => '  Support agent  ',
            'description' => '  Resolves customer requests.  ',
            'permissions' => [
                Permission::TenantMembersView->value,
                Permission::ProjectView->value,
                Permission::TenantView->value,
            ],
        ]);

        self::assertSame('SUPPORT_AGENT', $input->code);
        self::assertSame('Support agent', $input->name);
        self::assertSame(
            'Resolves customer requests.',
            $input->description,
        );
        self::assertSame([
            Permission::TenantView,
            Permission::TenantMembersView,
            Permission::ProjectView,
        ], $input->permissions);
        self::assertNull($input->expectedRevision);
    }

    public function testTenantRoleCannotUseInvalidOrIncompletePermissions(): void
    {
        try {
            (new TenantRoleDefinitionValidator())->forCreate([
                'code' => 'SUPPORT_AGENT',
                'name' => 'Support agent',
                'permissions' => [
                    Permission::SystemAuditView->value,
                    Permission::TenantMembersInvite->value,
                    Permission::TenantMembersInvite->value,
                    'tenant.not-real',
                ],
            ]);
            self::fail('Invalid tenant-role permissions must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'TENANT_ROLE_INPUT_INVALID',
                $exception->problemCode(),
            );
            self::assertArrayHasKey(
                'permissions',
                $exception->fieldErrors(),
            );
            self::assertCount(
                5,
                $exception->fieldErrors()['permissions'],
            );
        }
    }

    public function testUpdateRejectsCodeChangesAndInvalidRevision(): void
    {
        try {
            (new TenantRoleDefinitionValidator())->forUpdate([
                'code' => 'RENAMED_CODE',
                'name' => 'Renamed role',
                'description' => '',
                'permissions' => [],
                'revision' => 0,
            ]);
            self::fail('An update with an invalid contract must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'TENANT_ROLE_INPUT_INVALID',
                $exception->problemCode(),
            );
            self::assertArrayHasKey('body', $exception->fieldErrors());
            self::assertArrayHasKey(
                'revision',
                $exception->fieldErrors(),
            );
        }
    }
}
