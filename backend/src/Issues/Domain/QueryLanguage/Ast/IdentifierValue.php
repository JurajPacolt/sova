<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * A bare identifier value such as `BUG`, `HIGH` or `DONE`. It denotes an
 * enum member or a project-entity code depending on the field it is compared
 * against; the semantic layer decides which.
 */
final readonly class IdentifierValue implements Value
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
