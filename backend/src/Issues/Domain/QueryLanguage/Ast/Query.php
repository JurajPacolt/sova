<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * A parsed SovaQL query: an optional boolean filter and an optional ordered
 * list of sort terms. A null filter matches every issue inside the caller's
 * tenant and project scope, which the compiler always applies regardless.
 */
final readonly class Query
{
    /**
     * @param list<SortItem> $sort
     */
    public function __construct(
        public ?Expression $filter,
        public array $sort,
    ) {}
}
