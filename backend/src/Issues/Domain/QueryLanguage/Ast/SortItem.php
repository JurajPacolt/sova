<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

use Sova\Issues\Domain\QueryLanguage\SortDirection;
use Sova\Issues\Domain\QueryLanguage\SortNulls;

/**
 * One `ORDER BY` term. Direction defaults to ascending; NULLS placement is
 * optional and, when omitted, left to the compiler's deterministic default.
 */
final readonly class SortItem
{
    public function __construct(
        public FieldReference $field,
        public SortDirection $direction,
        public ?SortNulls $nulls,
    ) {}
}
