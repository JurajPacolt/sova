<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Domain;

/**
 * Normalises a saved query name for the per-owner uniqueness check.
 *
 * Case and surrounding or repeated whitespace must not be enough to make two
 * names look different, or a list would fill with entries a person cannot tell
 * apart. The displayed name keeps whatever the owner typed.
 */
final class SavedQueryName
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($collapsed);
    }
}
