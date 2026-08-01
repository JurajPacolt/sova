<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

final readonly class TransitionDetails
{
    /**
     * @param list<RuleView> $rules
     */
    public function __construct(
        public string $id,
        public string $workflowVersionId,
        public string $code,
        public string $name,
        /**
         * Always an explicit status: §6.3 rules out a "from any status"
         * transition expressed as an ambiguous null.
         */
        public string $fromStatusId,
        public string $toStatusId,
        public string $toStatusCode,
        public string $toStatusName,
        /** Extra permission this transition demands beyond `issue.transition`. */
        public ?string $permissionCode,
        public bool $isPrimary,
        public int $position,
        public array $rules = [],
    ) {}

    public function startsFrom(string $statusId): bool
    {
        return $this->fromStatusId === $statusId;
    }
}
