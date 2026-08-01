<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Sova\Issues\Application\Search\ResolvedReferences;
use Sova\Issues\Domain\QueryLanguage\Ast\Node;
use Sova\Issues\Domain\QueryLanguage\QueryError;
use Sova\Issues\Domain\QueryLanguage\QueryErrorCode;
use Sova\Issues\Domain\QueryLanguage\TemporalEvaluator;

/**
 * Mutable bookkeeping for one compilation: the bound parameters, their types and
 * any reference errors. Placeholder names are generated from a counter and never
 * from user input, so a value can never influence the shape of the statement.
 *
 * @internal used only by {@see IssueQueryCompiler}
 */
final class CompilerState
{
    /** @var array<string, list<int>|list<string>|int|string> */
    public array $parameters = [];

    /** @var array<string, ArrayParameterType|ParameterType> */
    public array $parameterTypes = [];

    /** @var list<QueryError> */
    public array $errors = [];

    private int $counter = 0;

    public function __construct(
        public readonly ResolvedReferences $references,
        public readonly TemporalEvaluator $clock,
    ) {}

    public function bind(string|int $value, ParameterType $type): string
    {
        $name = $this->nextName();
        $this->parameters[$name] = $value;
        $this->parameterTypes[$name] = $type;

        return $name;
    }

    /**
     * @param list<string>|list<int> $values
     */
    public function bindList(array $values, ArrayParameterType $type): string
    {
        $name = $this->nextName();
        $this->parameters[$name] = $values;
        $this->parameterTypes[$name] = $type;

        return $name;
    }

    public function fail(QueryErrorCode $code, Node $node): void
    {
        $this->errors[] = new QueryError($code, $node->start(), $node->end());
    }

    private function nextName(): string
    {
        return sprintf('q%d', $this->counter++);
    }
}
