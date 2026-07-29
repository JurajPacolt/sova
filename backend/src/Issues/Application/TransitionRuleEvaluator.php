<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use Sova\ProjectConfiguration\Application\RuleView;
use Sova\ProjectConfiguration\Domain\TransitionRuleType;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

/**
 * Runs the resolution-related transition rules from WORKFLOW-A-TYPY-ULOH.md
 * §6.3 at transition time. Conditions decide whether a transition is offered,
 * validators guard the data it needs and actions compute the resolution effect
 * the repository applies together with the status change.
 *
 * The `required_field` validator is deliberately not executed: per-type custom
 * fields (§5.3) do not exist yet, so it stays a documented boundary and never
 * silently blocks a transition. Every unknown key is ignored because the graph
 * validation and the fixed rule catalog keep it out of a published version.
 */
final readonly class TransitionRuleEvaluator
{
    public const FIELD_RESOLUTION = 'resolution';

    /**
     * Whether the actor satisfies every CONDITION rule on the transition.
     *
     * @param list<RuleView>          $rules
     * @param callable(?string): bool $permissionCheck evaluates a permission code in the project scope
     */
    public function conditionsSatisfied(
        array $rules,
        callable $permissionCheck,
        TransitionActor $actor,
        ?string $assigneeMembershipId,
    ): bool {
        foreach ($rules as $rule) {
            if ($rule->ruleType !== TransitionRuleType::Condition) {
                continue;
            }

            $satisfied = match ($rule->ruleKey) {
                'permission' => $this->permissionRuleSatisfied($rule, $permissionCheck),
                'assignee_or_manager' => $actor->isManager
                    || $actor->isAssignee($assigneeMembershipId),
                default => true,
            };

            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Field keys the actor must supply for this transition. Currently only a
     * resolution, and only when a validator requires one and no action sets it.
     *
     * @param list<RuleView> $rules
     *
     * @return list<string>
     */
    public function requiredFields(array $rules): array
    {
        return $this->requiresSuppliedResolution($rules) ? [self::FIELD_RESOLUTION] : [];
    }

    /**
     * Computes the resolution effect of the transition's ACTION rules and
     * validates the resulting state against its VALIDATOR rules.
     *
     * @param list<RuleView> $rules
     *
     * @throws DomainProblemException when a validator is not satisfied
     */
    public function apply(
        array $rules,
        ?string $suppliedResolution,
        ?string $currentResolution,
    ): TransitionEffect {
        $touchesResolution = false;
        $resolution = $currentResolution;
        $touchesResolvedAt = false;
        $resolvedAtToNow = false;

        foreach ($rules as $rule) {
            if ($rule->ruleType !== TransitionRuleType::Action) {
                continue;
            }

            switch ($rule->ruleKey) {
                case 'set_resolution':
                    $touchesResolution = true;
                    $resolution = $this->stringConfig($rule, 'resolution');
                    break;
                case 'clear_resolution':
                    $touchesResolution = true;
                    $resolution = null;
                    break;
                case 'set_resolved_at':
                    $touchesResolvedAt = true;
                    $resolvedAtToNow = true;
                    break;
                case 'clear_resolved_at':
                    $touchesResolvedAt = true;
                    $resolvedAtToNow = false;
                    break;
            }
        }

        // A client-supplied resolution applies only when no action fixed it.
        if (!$touchesResolution && $this->requiresSuppliedResolution($rules)) {
            $clean = $suppliedResolution === null ? '' : trim($suppliedResolution);

            if ($clean !== '') {
                $touchesResolution = true;
                $resolution = $clean;
            }
        }

        $this->validate($rules, $touchesResolution ? $resolution : $currentResolution);

        return new TransitionEffect(
            $touchesResolution,
            $resolution,
            $touchesResolvedAt,
            $resolvedAtToNow,
        );
    }

    /**
     * @param callable(?string): bool $permissionCheck
     */
    private function permissionRuleSatisfied(RuleView $rule, callable $permissionCheck): bool
    {
        $permission = $this->stringConfig($rule, 'permission');

        return $permission !== null && $permissionCheck($permission);
    }

    /**
     * @param list<RuleView> $rules
     *
     * @throws DomainProblemException
     */
    private function validate(array $rules, ?string $finalResolution): void
    {
        foreach ($rules as $rule) {
            if ($rule->ruleType !== TransitionRuleType::Validator) {
                continue;
            }

            if (
                $rule->ruleKey === 'resolution_required'
                && ($finalResolution === null || trim($finalResolution) === '')
            ) {
                throw new DomainProblemException(
                    ProblemType::ValidationFailed,
                    'ISSUE_TRANSITION_INVALID',
                    'This transition requires a resolution.',
                    ['resolution' => ['Provide a resolution for this transition.']],
                );
            }
        }
    }

    /**
     * @param list<RuleView> $rules
     */
    private function requiresSuppliedResolution(array $rules): bool
    {
        $requiresResolution = false;
        $setsResolution = false;

        foreach ($rules as $rule) {
            if (
                $rule->ruleType === TransitionRuleType::Validator
                && $rule->ruleKey === 'resolution_required'
            ) {
                $requiresResolution = true;
            }

            if (
                $rule->ruleType === TransitionRuleType::Action
                && $rule->ruleKey === 'set_resolution'
            ) {
                $setsResolution = true;
            }
        }

        return $requiresResolution && !$setsResolution;
    }

    private function stringConfig(RuleView $rule, string $key): ?string
    {
        $value = $rule->configuration[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
