<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * A workflow transition inside a specific version, including its ordered rule
 * register. Unlike {@see TransitionDetails}, which is the lean runtime shape,
 * this view carries everything the configuration editor needs.
 */
final readonly class TransitionView
{
    /**
     * @param list<RuleView> $rules
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $fromStatusId,
        public string $toStatusId,
        public ?string $permissionCode,
        public bool $isPrimary,
        public int $position,
        public array $rules,
    ) {}
}
