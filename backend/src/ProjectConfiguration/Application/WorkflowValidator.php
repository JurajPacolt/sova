<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\StatusCategory;
use Sova\ProjectConfiguration\Domain\TransitionRuleCatalog;
use Sova\ProjectConfiguration\Domain\WorkflowValidationError;

/**
 * The graph validation from WORKFLOW-A-TYPY-ULOH.md §6.4. A version that fails
 * any rule cannot be published. Pure over its input, so it is exercised both at
 * draft save time and again inside the publish transaction.
 */
final readonly class WorkflowValidator
{
    /**
     * @return list<WorkflowValidationError>
     */
    public function validate(WorkflowVersionView $version): array
    {
        $statusById = [];

        foreach ($version->statuses as $status) {
            $statusById[$status->statusId] = $status;
        }

        $errors = [];
        $errors = [...$errors, ...$this->validateStatuses($version, $statusById)];
        $errors = [...$errors, ...$this->validateTransitions($version, $statusById)];
        $errors = [...$errors, ...$this->validatePrimaryActions($version)];
        $errors = [...$errors, ...$this->validateReachability($version, $statusById)];

        return $errors;
    }

    /**
     * @param array<string, VersionStatusView> $statusById
     *
     * @return list<WorkflowValidationError>
     */
    private function validateStatuses(WorkflowVersionView $version, array $statusById): array
    {
        $errors = [];

        if ($version->statuses === []) {
            $errors[] = new WorkflowValidationError(
                'WORKFLOW_NO_STATUSES',
                'A workflow must contain at least one status.',
            );
        }

        if ($version->initialStatusId === null) {
            $errors[] = new WorkflowValidationError(
                'WORKFLOW_NO_INITIAL_STATUS',
                'A workflow must define exactly one initial status.',
            );
        } elseif (!isset($statusById[$version->initialStatusId])) {
            $errors[] = new WorkflowValidationError(
                'WORKFLOW_INITIAL_STATUS_NOT_MEMBER',
                'The initial status must be one of the workflow statuses.',
            );
        }

        return $errors;
    }

    /**
     * @param array<string, VersionStatusView> $statusById
     *
     * @return list<WorkflowValidationError>
     */
    private function validateTransitions(WorkflowVersionView $version, array $statusById): array
    {
        $errors = [];

        foreach ($version->transitions as $transition) {
            if (
                !isset($statusById[$transition->fromStatusId])
                || !isset($statusById[$transition->toStatusId])
            ) {
                $errors[] = new WorkflowValidationError(
                    'WORKFLOW_TRANSITION_STATUS_NOT_MEMBER',
                    sprintf(
                        'Transition "%s" links a status outside this workflow.',
                        $transition->code,
                    ),
                );
            }

            foreach ($transition->rules as $rule) {
                if (!TransitionRuleCatalog::supports($rule->ruleType, $rule->ruleKey)) {
                    $errors[] = new WorkflowValidationError(
                        'WORKFLOW_RULE_UNSUPPORTED',
                        sprintf(
                            'Transition "%s" references unsupported rule "%s".',
                            $transition->code,
                            $rule->ruleKey,
                        ),
                    );

                    continue;
                }

                foreach (
                    TransitionRuleCatalog::validateConfiguration(
                        $rule->ruleKey,
                        $rule->configuration,
                    ) as $problem
                ) {
                    $errors[] = new WorkflowValidationError(
                        'WORKFLOW_RULE_CONFIG_INVALID',
                        sprintf('Transition "%s": %s', $transition->code, $problem),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<WorkflowValidationError>
     */
    private function validatePrimaryActions(WorkflowVersionView $version): array
    {
        /** @var array<string, int> $primaryBySource */
        $primaryBySource = [];

        foreach ($version->transitions as $transition) {
            if (!$transition->isPrimary) {
                continue;
            }

            $primaryBySource[$transition->fromStatusId]
                = ($primaryBySource[$transition->fromStatusId] ?? 0) + 1;
        }

        $errors = [];

        foreach ($primaryBySource as $count) {
            if ($count > 1) {
                $errors[] = new WorkflowValidationError(
                    'WORKFLOW_MULTIPLE_PRIMARY_ACTIONS',
                    'A source status may have at most one primary action.',
                );

                break;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, VersionStatusView> $statusById
     *
     * @return list<WorkflowValidationError>
     */
    private function validateReachability(WorkflowVersionView $version, array $statusById): array
    {
        $initial = $version->initialStatusId;

        if ($initial === null || !isset($statusById[$initial])) {
            return [];
        }

        $reachable = $this->reachableStatusIds($version, $statusById, $initial);
        $errors = [];

        foreach ($version->statuses as $status) {
            if (!isset($reachable[$status->statusId])) {
                $errors[] = new WorkflowValidationError(
                    'WORKFLOW_STATUS_UNREACHABLE',
                    sprintf(
                        'Status "%s" is not reachable from the initial status.',
                        $status->code,
                    ),
                );
            }
        }

        if (!$this->reachesDoneCategory($reachable, $statusById)) {
            $errors[] = new WorkflowValidationError(
                'WORKFLOW_NO_REACHABLE_DONE',
                'At least one DONE status must be reachable from the initial status.',
            );
        }

        return $errors;
    }

    /**
     * @param array<string, VersionStatusView> $statusById
     *
     * @return array<string, true>
     */
    private function reachableStatusIds(
        WorkflowVersionView $version,
        array $statusById,
        string $initial,
    ): array {
        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];

        foreach ($version->transitions as $transition) {
            if (
                isset($statusById[$transition->fromStatusId])
                && isset($statusById[$transition->toStatusId])
            ) {
                $adjacency[$transition->fromStatusId][] = $transition->toStatusId;
            }
        }

        $reachable = [$initial => true];
        $queue = [$initial];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($adjacency[$current] ?? [] as $next) {
                if (!isset($reachable[$next])) {
                    $reachable[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $reachable;
    }

    /**
     * @param array<string, true>              $reachable
     * @param array<string, VersionStatusView> $statusById
     */
    private function reachesDoneCategory(array $reachable, array $statusById): bool
    {
        foreach (array_keys($reachable) as $statusId) {
            if ($statusById[$statusId]->category === StatusCategory::Done) {
                return true;
            }
        }

        return false;
    }
}
