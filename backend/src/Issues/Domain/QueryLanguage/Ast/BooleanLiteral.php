<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

final readonly class BooleanLiteral implements Value
{
    public function __construct(
        public bool $value,
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
