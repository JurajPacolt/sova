<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

/**
 * The actor a transition is evaluated for, holding only what the transition
 * rules need beyond a plain permission check: the actor's membership in the
 * issue's tenant (to match the assignee) and whether they hold the project's
 * manager permission. Authorization itself stays in the presentation layer,
 * which decides which permission counts as "manager".
 */
final readonly class TransitionActor
{
    public function __construct(
        public ?string $membershipId,
        public bool $isManager,
    ) {}

    public function isAssignee(?string $assigneeMembershipId): bool
    {
        return $this->membershipId !== null
            && $assigneeMembershipId !== null
            && $this->membershipId === $assigneeMembershipId;
    }
}
