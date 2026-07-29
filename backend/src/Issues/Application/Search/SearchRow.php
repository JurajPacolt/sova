<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * A hit together with the sort values it was ordered by. The values are what the
 * next cursor is built from, which is why they come back from the database
 * rather than being recomputed in PHP — recomputation would risk disagreeing
 * with the collation or the `CASE` ranking the query actually used.
 */
final readonly class SearchRow
{
    /**
     * @param list<string|null> $sortValues
     */
    public function __construct(
        public SearchResultItem $item,
        public array $sortValues,
    ) {}
}
