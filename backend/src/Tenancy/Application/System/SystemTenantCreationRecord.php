<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

final readonly class SystemTenantCreationRecord
{
    public function __construct(
        public string $requestFingerprint,
        public string $tenantId,
    ) {}
}
