<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

use Sova\Tenancy\Domain\Tenant\TenantStatus;

final readonly class SystemTenantLifecycleInput
{
    public function __construct(
        public TenantStatus $status,
        public int $revision,
        public string $reason,
    ) {}
}
