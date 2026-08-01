<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

final readonly class SystemTenantInput
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $ownerEmail,
    ) {}
}
