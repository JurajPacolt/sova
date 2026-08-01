<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * A field reference such as `assignee`, `statusCategory` or a future
 * `cf.<key>`. The raw name preserves the author's casing and any dotted
 * namespace; canonicalization and support checks happen against the catalog.
 */
final readonly class FieldReference implements Node
{
    public function __construct(
        public string $name,
        private int $start,
        private int $end,
    ) {}

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->end;
    }
}
