<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * A field the language knows about. Availability is separate from existence:
 * `watcher`, `labels`, `due`, `estimate` and `closed` are valid v1 field names
 * whose backing columns arrive in a later phase, so they resolve here but are
 * flagged unsupported and rejected with `QUERY_FIELD_NOT_SUPPORTED` until then.
 */
final readonly class FieldDefinition
{
    /**
     * @param list<ComparisonOperator> $comparisons operators valid outside
     *                                              `IN`/`IS EMPTY`
     */
    public function __construct(
        public string $canonicalName,
        public FieldType $type,
        public bool $supported,
        public array $comparisons,
        public bool $allowsSet,
        public bool $allowsEmpty,
        public bool $sortable,
    ) {}

    public function allowsComparison(ComparisonOperator $operator): bool
    {
        return in_array($operator, $this->comparisons, true);
    }
}
