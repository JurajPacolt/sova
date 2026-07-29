<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\TemporalEvaluator;

/**
 * Turns a validated AST plus resolved references into a storage-level query.
 * The port keeps the application service independent of SQL; the adapter owns
 * the whitelist that makes the translation safe.
 */
interface QueryCompiler
{
    public function compile(
        Query $query,
        ResolvedReferences $references,
        ?TemporalEvaluator $clock = null,
    ): CompiledQuery;
}
