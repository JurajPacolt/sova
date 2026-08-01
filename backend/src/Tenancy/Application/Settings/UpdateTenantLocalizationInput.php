<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Settings;

final readonly class UpdateTenantLocalizationInput
{
    public function __construct(
        public string $defaultLocale,
        public string $timezone,
        public int $expectedRevision,
    ) {}
}
