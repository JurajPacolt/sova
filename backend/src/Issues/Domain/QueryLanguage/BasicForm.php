<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * The query as the basic (control-based) editor can show it.
 *
 * {@see $representable} is the whole point. The basic editor can only express a
 * conjunction of simple conditions; anything with `OR`, `NOT` or grouping has a
 * meaning it cannot carry. The specification is explicit that such a query must
 * be shown read-only with a way back to the text editor rather than quietly
 * simplified — silently dropping half of someone's filter and then running it
 * would be far worse than refusing to draw it.
 */
final readonly class BasicForm
{
    /**
     * @param list<BasicCondition> $conditions
     * @param list<BasicSort>      $sort
     */
    private function __construct(
        public bool $representable,
        public array $conditions,
        public array $sort,
    ) {}

    /**
     * @param list<BasicCondition> $conditions
     * @param list<BasicSort>      $sort
     */
    public static function of(array $conditions, array $sort): self
    {
        return new self(true, $conditions, $sort);
    }

    /**
     * @param list<BasicSort> $sort
     */
    public static function tooComplex(array $sort): self
    {
        return new self(false, [], $sort);
    }
}
