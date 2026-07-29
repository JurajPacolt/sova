<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\WorkflowValidationError;

/**
 * The publishing impact report from WORKFLOW-A-TYPY-ULOH.md §8.1: what would
 * change if the current draft were published, which issues are affected and
 * which removed statuses still need a migration target.
 */
final readonly class ImpactReport
{
    /**
     * @param list<WorkflowValidationError> $validationErrors
     * @param list<string>                  $typeCodesUsingWorkflow
     * @param list<string>                  $addedStatusCodes
     * @param list<string>                  $removedStatusCodes
     * @param list<string>                  $addedTransitionCodes
     * @param list<string>                  $removedTransitionCodes
     * @param list<StatusIssueCount>        $affectedIssueCounts
     * @param list<string>                  $requiredStatusMappingCodes
     */
    public function __construct(
        public string $workflowId,
        public int $expectedConfigVersion,
        public array $validationErrors,
        public array $typeCodesUsingWorkflow,
        public array $addedStatusCodes,
        public array $removedStatusCodes,
        public array $addedTransitionCodes,
        public array $removedTransitionCodes,
        public array $affectedIssueCounts,
        public array $requiredStatusMappingCodes,
    ) {}

    public function requiresMigration(): bool
    {
        return $this->requiredStatusMappingCodes !== [];
    }

    public function isPublishable(): bool
    {
        return $this->validationErrors === [];
    }
}
