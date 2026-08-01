<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/** A double-quoted text value with escape sequences already resolved. */
final readonly class StringLiteral implements Value
{
    public function __construct(
        public string $value,
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
