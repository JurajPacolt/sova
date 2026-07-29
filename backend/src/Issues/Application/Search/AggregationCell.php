<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/** One cell of a two-dimensional breakdown. */
final readonly class AggregationCell
{
    public function __construct(
        public ?string $rowKey,
        public ?string $rowLabel,
        public ?string $columnKey,
        public ?string $columnLabel,
        public int $count,
    ) {}
}
