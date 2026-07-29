<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * One authorised page of results. There is no total count: counting would cost a
 * second full scan of the same authorised set, and cursor pagination does not
 * need it. {@see $nextCursor} is null exactly when this is the last page.
 */
final readonly class SearchOutcome
{
    /**
     * @param list<SearchResultItem> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public string $canonicalQuery,
        public int $pageSize,
    ) {}
}
