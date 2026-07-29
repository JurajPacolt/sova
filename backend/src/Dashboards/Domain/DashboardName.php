<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain;

/**
 * Normalises a dashboard name for the per-owner uniqueness check.
 *
 * Same reasoning as for saved queries: case and repeated whitespace must not be
 * enough to make two names look different, or the switcher fills with entries
 * nobody can tell apart. The displayed name keeps whatever the owner typed.
 */
final class DashboardName
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($collapsed);
    }
}
