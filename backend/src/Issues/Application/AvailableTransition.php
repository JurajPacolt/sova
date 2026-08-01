<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use Sova\ProjectConfiguration\Application\TransitionDetails;

final readonly class AvailableTransition
{
    /**
     * @param list<string> $requiredFields field keys the actor must supply
     */
    public function __construct(
        public TransitionDetails $transition,
        /** Issue version the list was computed against. */
        public int $issueVersion,
        public array $requiredFields = [],
    ) {}
}
