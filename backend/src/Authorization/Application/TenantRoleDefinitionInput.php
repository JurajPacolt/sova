<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Sova\Authorization\Domain\Permission;

final readonly class TenantRoleDefinitionInput
{
    /**
     * @param list<Permission> $permissions
     */
    private function __construct(
        public ?string $code,
        public string $name,
        public string $description,
        public array $permissions,
        public ?int $expectedRevision,
    ) {}

    /**
     * @param list<Permission> $permissions
     */
    public static function forCreate(
        string $code,
        string $name,
        string $description,
        array $permissions,
    ): self {
        return new self(
            code: $code,
            name: $name,
            description: $description,
            permissions: $permissions,
            expectedRevision: null,
        );
    }

    /**
     * @param list<Permission> $permissions
     */
    public static function forUpdate(
        string $name,
        string $description,
        array $permissions,
        int $expectedRevision,
    ): self {
        return new self(
            code: null,
            name: $name,
            description: $description,
            permissions: $permissions,
            expectedRevision: $expectedRevision,
        );
    }
}
