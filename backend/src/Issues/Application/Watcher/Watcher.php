<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Watcher;

use DateTimeImmutable;

final readonly class Watcher
{
    public function __construct(
        public string $membershipId,
        public ?string $displayName,
        public WatchSource $source,
        public DateTimeImmutable $since,
    ) {}
}
