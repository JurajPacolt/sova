<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Settings;

interface TenantSettingsRepository
{
    public function find(string $tenantId, bool $forUpdate = false): ?TenantSettingsDetails;

    public function updateGeneral(
        string $tenantId,
        int $expectedRevision,
        string $name,
    ): bool;

    public function updateLocalization(
        string $tenantId,
        int $expectedRevision,
        string $defaultLocale,
        string $timezone,
    ): bool;
}
