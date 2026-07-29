<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

/**
 * The three kinds of transition rule from WORKFLOW-A-TYPY-ULOH.md §6.3. A
 * condition decides whether the transition is offered, a validator guards the
 * data it needs and an action mutates the issue after it runs.
 */
enum TransitionRuleType: string
{
    case Condition = 'CONDITION';
    case Validator = 'VALIDATOR';
    case Action = 'ACTION';
}
