<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * A query that has passed every step of the security contract and is ready to
 * run: the authorised scope it belongs to, the compiled filter, and the
 * canonical text it was produced from.
 *
 * It exists so aggregation reuses the **same** sequence as search rather than
 * repeating it — the order of those steps is the contract, and a second copy is
 * a second place for it to drift.
 */
final readonly class QueryPlan
{
    public function __construct(
        public SearchScope $scope,
        public CompiledQuery $compiled,
        public string $canonical,
    ) {}
}
