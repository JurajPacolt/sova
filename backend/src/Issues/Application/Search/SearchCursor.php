<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * An opaque keyset page token.
 *
 * The cursor is the last row of the previous page expressed as its sort values
 * plus the tie-breaking issue id. It is signed and bound to the tenant, the
 * effective user, the tenant authorization revision, the canonical query hash
 * and the sort specification, so changing the query, the sort or the caller's
 * permissions invalidates every token they still hold (spec §4.10). It carries
 * no row data beyond what the caller was already shown.
 */
final readonly class SearchCursor
{
    /**
     * @param list<string|null> $sortValues values of the compiled sort terms,
     *                                      in order, for the last row returned
     */
    public function __construct(
        public array $sortValues,
        public string $issueId,
    ) {}
}
