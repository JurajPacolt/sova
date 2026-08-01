<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * A function call such as `currentUser()`, `membersOf("Backend")` or
 * `startOfDay("-7d")`. Functions may appear as a scalar value or, for
 * set-returning functions, as the right-hand side of an `IN` predicate.
 */
final readonly class FunctionCall implements Value
{
    /**
     * @param list<Value> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments,
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
