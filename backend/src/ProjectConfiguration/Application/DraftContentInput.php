<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * The full replacement content of a workflow draft: its membership statuses,
 * the initial status and the ordered transitions with their rules. The draft's
 * optimistic-lock version guards concurrent editors (§8.3).
 */
final readonly class DraftContentInput
{
    /**
     * @param list<DraftStatusInput>     $statuses
     * @param list<DraftTransitionInput> $transitions
     */
    public function __construct(
        public int $expectedVersion,
        public string $initialStatusCode,
        public array $statuses,
        public array $transitions,
    ) {}
}
