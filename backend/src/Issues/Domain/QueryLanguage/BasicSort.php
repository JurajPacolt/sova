<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * One `ORDER BY` term as the basic editor shows it. Sorting is a flat list in
 * both editors, so it always survives the switch between them.
 */
final readonly class BasicSort
{
    public function __construct(
        public string $field,
        public string $direction,
        public ?string $nulls,
    ) {}
}
