<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Issues\Domain\QueryLanguage\SortDirection;

/**
 * One resolved `ORDER BY` term. {@see $expression} is a constant SQL snippet
 * chosen from the compiler's whitelist — never assembled from a user token — and
 * {@see $alias} is the column the keyset cursor reads its value back from.
 */
final readonly class CompiledSort
{
    public function __construct(
        public string $field,
        public string $expression,
        public string $alias,
        public SortDirection $direction,
        public bool $nullsFirst,
        public bool $numeric,
    ) {}
}
