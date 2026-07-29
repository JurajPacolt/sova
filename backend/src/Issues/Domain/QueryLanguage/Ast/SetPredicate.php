<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * `field IN (a, b, ...)` / `field NOT IN (...)`. The right-hand side is either
 * an explicit value list or a single set-returning function such as
 * `membersOf("Backend")`.
 */
final readonly class SetPredicate implements Expression
{
    /**
     * @param list<Value> $values explicit members; empty when {@see $function}
     *                            is used
     */
    public function __construct(
        public FieldReference $field,
        public bool $negated,
        public array $values,
        public ?FunctionCall $function,
        public int $operatorStart,
        public int $operatorEnd,
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
