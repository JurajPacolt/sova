<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http;

use Sova\Tenancy\Application\Settings\TenantSettingsDetails;

final class TenantSettingsSerializer
{
    /**
     * @return array{
     *     tenant_id: string,
     *     name: string,
     *     slug: string,
     *     default_locale: string,
     *     timezone: string,
     *     revision: int
     * }
     */
    public function serialize(TenantSettingsDetails $settings): array
    {
        return [
            'tenant_id' => $settings->tenantId,
            'name' => $settings->name,
            'slug' => $settings->slug,
            'default_locale' => $settings->defaultLocale,
            'timezone' => $settings->timezone,
            'revision' => $settings->revision,
        ];
    }
}
