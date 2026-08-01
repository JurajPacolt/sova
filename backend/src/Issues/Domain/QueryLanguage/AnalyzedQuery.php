<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use Sova\Issues\Domain\QueryLanguage\Ast\Query;

/**
 * Outcome of analysing a SovaQL string: the parsed AST (when the structure is
 * sound), any collected errors, and the canonical text (only when the query is
 * fully valid). A valid query always carries both an AST and a canonical form.
 */
final readonly class AnalyzedQuery
{
    /**
     * @param list<QueryError> $errors
     */
    public function __construct(
        public bool $valid,
        public ?Query $ast,
        public array $errors,
        public ?string $canonical,
    ) {}
}
