<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * Every AST element exposes the codepoint range it was parsed from so semantic
 * errors can point at the exact text the editor should highlight.
 */
interface Node
{
    public function start(): int;

    public function end(): int;
}
