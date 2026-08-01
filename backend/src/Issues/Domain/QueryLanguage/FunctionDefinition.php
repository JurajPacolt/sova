<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

final readonly class FunctionDefinition
{
    public function __construct(
        public string $canonicalName,
        public FunctionReturnType $returnType,
        public int $minArguments,
        public int $maxArguments,
        public FunctionArgumentKind $argumentKind,
    ) {}
}
