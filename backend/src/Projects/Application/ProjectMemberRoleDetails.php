<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

final readonly class ProjectMemberRoleDetails
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
    ) {}
}
