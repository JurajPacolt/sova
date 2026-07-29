<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * One row of the basic editor: a field, an operator and the values it compares
 * against, all already in canonical form so the builder can put them straight
 * back into SovaQL text without inventing its own formatting.
 */
final readonly class BasicCondition
{
    /**
     * @param list<string> $values canonical value texts; empty for `IS EMPTY`
     */
    public function __construct(
        public string $field,
        public string $operator,
        public array $values,
    ) {}
}
