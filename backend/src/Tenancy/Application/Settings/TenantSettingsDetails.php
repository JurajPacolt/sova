<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Settings;

final readonly class TenantSettingsDetails
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $slug,
        public string $defaultLocale,
        public string $timezone,
        public int $revision,
    ) {}
}
