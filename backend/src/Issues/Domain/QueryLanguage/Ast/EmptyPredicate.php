<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/** `field IS EMPTY` / `field IS NOT EMPTY`. */
final readonly class EmptyPredicate implements Expression
{
    public function __construct(
        public FieldReference $field,
        public bool $negated,
        public int $operatorStart,
        private int $end,
    ) {}

    public function start(): int
    {
        return $this->field->start();
    }

    public function end(): int
    {
        return $this->end;
    }
}
