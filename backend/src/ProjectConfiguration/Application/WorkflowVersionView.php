<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\WorkflowVersionState;

/**
 * The full content of one workflow version: its membership statuses, its
 * transitions with rules, the initial status and the optimistic-lock version.
 */
final readonly class WorkflowVersionView
{
    /**
     * @param list<VersionStatusView> $statuses
     * @param list<TransitionView>    $transitions
     */
    public function __construct(
        public string $id,
        public string $workflowId,
        public int $versionNumber,
        public WorkflowVersionState $state,
        public int $optimisticVersion,
        public ?string $initialStatusId,
        public array $statuses,
        public array $transitions,
    ) {}

    public function containsStatus(string $statusId): bool
    {
        foreach ($this->statuses as $status) {
            if ($status->statusId === $statusId) {
                return true;
            }
        }

        return false;
    }
}
