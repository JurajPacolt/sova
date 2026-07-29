<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Authorization\Application\AuthorizationSubject;

/**
 * Derives the authorised search boundary from the database. Implementations must
 * take the tenant from the verified route context and must never widen the scope
 * from request input.
 */
interface SearchScopeProvider
{
    public function scopeFor(AuthorizationSubject $subject, string $tenantId): SearchScope;
}
