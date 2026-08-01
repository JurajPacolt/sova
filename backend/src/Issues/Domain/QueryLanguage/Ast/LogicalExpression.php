<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

use Sova\Issues\Domain\QueryLanguage\LogicalOperator;

/** A binary `AND`/`OR` connective. */
final readonly class LogicalExpression implements Expression
{
    public function __construct(
        public LogicalOperator $operator,
        public Expression $left,
        public Expression $right,
    ) {}

    public function start(): int
    {
        return $this->left->start();
    }

    public function end(): int
    {
        return $this->right->end();
    }
}
