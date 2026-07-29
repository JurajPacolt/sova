<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use RuntimeException;

/**
 * Raised by the lexer and parser when the text cannot form an AST. Syntax
 * failures abort parsing (unlike semantic errors, which are collected), so this
 * carries exactly one {@see QueryError}.
 */
final class SovaQlSyntaxException extends RuntimeException
{
    public function __construct(private readonly QueryError $error)
    {
        parent::__construct($error->code->value);
    }

    public function error(): QueryError
    {
        return $this->error;
    }
}
