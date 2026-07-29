<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Domain;

/**
 * What a grant lets a principal do with a shared query.
 *
 * `EDIT` covers using and changing the query, never changing its owner or its
 * grants — those stay with the owner and with `saved-query.manage` (spec §6.2).
 * Neither level ever conveys access to the issues the query returns.
 */
enum SavedQueryAccess: string
{
    case View = 'VIEW';
    case Edit = 'EDIT';

    public function allowsEditing(): bool
    {
        return $this === self::Edit;
    }
}
