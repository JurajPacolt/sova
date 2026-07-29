<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

use Sova\Issues\Domain\QueryLanguage\ComparisonOperator;

/** `field <op> value`, e.g. `priority = HIGH` or `created >= startOfDay("-7d")`. */
final readonly class ComparisonPredicate implements Expression
{
    public function __construct(
        public FieldReference $field,
        public ComparisonOperator $operator,
        public Value $value,
        public int $operatorStart,
        public int $operatorEnd,
    ) {}

    public function start(): int
    {
        return $this->field->start();
    }

    public function end(): int
    {
        return $this->value->end();
    }
}
