<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use DateTimeImmutable;

/** The start of one bucket and how many events fell inside it. */
final readonly class TimeSeriesPoint
{
    public function __construct(
        public DateTimeImmutable $bucket,
        public int $count,
    ) {}
}
