<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

/**
 * The resolution mutation a transition applies to an issue alongside its status
 * change. A flag guards each column so a transition that does not touch the
 * resolution leaves it untouched instead of clearing it.
 */
final readonly class TransitionEffect
{
    public function __construct(
        public bool $touchesResolution,
        public ?string $resolution,
        public bool $touchesResolvedAt,
        public bool $resolvedAtToNow,
    ) {}

    public static function none(): self
    {
        return new self(false, null, false, false);
    }
}
