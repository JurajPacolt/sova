<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * Operator-configurable safety limits (spec §4.12). Defaults are the
 * recommended starting values; the search metadata endpoint echoes the active
 * numbers so the editor can guide the user before a request is rejected.
 */
final readonly class QueryLimits
{
    public function __construct(
        public int $maxQueryBytes = 8192,
        public int $maxAstNodes = 100,
        public int $maxParenDepth = 10,
        public int $maxInValues = 100,
        public int $maxSortFields = 3,
        public int $defaultPageSize = 50,
        public int $maxPageSize = 100,
        public int $statementTimeoutMs = 3000,
    ) {}
}
