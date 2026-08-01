<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Issues\Domain\QueryLanguage\BasicForm;
use Sova\Issues\Domain\QueryLanguage\QueryError;

/**
 * The full validation verdict for one query text, in the shape spec §4.11
 * defines: a boolean, the stable errors with their ranges, and the canonical
 * form when there is nothing left to fix. Reference errors are included, so the
 * editor learns about an unreachable project code in the same round trip as a
 * syntax error.
 */
final readonly class IssueQueryValidation
{
    /**
     * @param list<QueryError> $errors
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public ?string $canonical,
        /**
         * How the control-based editor may show the query, or null when it
         * could not be parsed at all. `representable = false` means the query
         * is legal but has no basic-editor shape; the client must then show it
         * read-only instead of simplifying it.
         */
        public ?BasicForm $basicForm = null,
    ) {}
}
