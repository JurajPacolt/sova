<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use InvalidArgumentException;
use Sova\Authorization\Domain\Permission;
use Sova\Authorization\Domain\PermissionScope;

final readonly class AuthorizationScope
{
    private function __construct(
        public PermissionScope $type,
        public ?string $tenantId,
        public ?string $projectId,
        public ?string $workgroupId,
    ) {}

    public static function system(): self
    {
        return new self(PermissionScope::System, null, null, null);
    }

    public static function tenant(string $tenantId): self
    {
        self::assertIdentifier($tenantId, 'tenant');

        return new self(PermissionScope::Tenant, $tenantId, null, null);
    }

    public static function project(string $tenantId, string $projectId): self
    {
        self::assertIdentifier($tenantId, 'tenant');
        self::assertIdentifier($projectId, 'project');

        return new self(
            PermissionScope::Project,
            $tenantId,
            $projectId,
            null,
        );
    }

    public static function workgroup(
        string $tenantId,
        string $workgroupId,
    ): self {
        self::assertIdentifier($tenantId, 'tenant');
        self::assertIdentifier($workgroupId, 'workgroup');

        return new self(
            PermissionScope::Workgroup,
            $tenantId,
            null,
            $workgroupId,
        );
    }

    public function supports(Permission $permission): bool
    {
        return $permission->scope() === $this->type;
    }

    private static function assertIdentifier(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'Authorization %s identifier must not be empty.',
                $name,
            ));
        }
    }
}
