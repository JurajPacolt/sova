<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Sova\Issues\Domain\QueryLanguage\QueryError;

/**
 * The result of compiling a validated AST inside a scope: either a parameterised
 * SQL filter plus its ordering, or the reference errors that stopped it. Values
 * never appear in {@see $filterSql} — they are bound parameters — and column
 * names come only from the compiler's whitelist.
 */
final readonly class CompiledQuery
{
    /**
     * @param array<string, list<int>|list<string>|int|string>       $parameters
     * @param array<string, ArrayParameterType|ParameterType>         $parameterTypes
     * @param list<CompiledSort>                                      $sort
     * @param list<QueryError>                                        $errors
     */
    public function __construct(
        public string $filterSql,
        public array $parameters,
        public array $parameterTypes,
        public array $sort,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
