<?php

declare(strict_types=1);

namespace Sova\Authorization\Domain;

final readonly class PermissionDefinition
{
    /**
     * @param list<Permission> $dependencies
     */
    public function __construct(
        public Permission $permission,
        public string $label,
        public string $description,
        public array $dependencies,
    ) {}
}
