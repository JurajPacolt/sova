<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

final readonly class NotExpression implements Expression
{
    public function __construct(
        public Expression $operand,
        private int $start,
    ) {}

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->operand->end();
    }
}
