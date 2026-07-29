<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * Resolves the external names a query mentions into identifiers, strictly inside
 * the given scope. Anything outside it must be reported as unresolved rather
 * than as forbidden, so error responses cannot enumerate foreign configuration.
 */
interface ReferenceResolver
{
    public function resolve(SearchScope $scope, ReferenceRequest $request): ResolvedReferences;
}
