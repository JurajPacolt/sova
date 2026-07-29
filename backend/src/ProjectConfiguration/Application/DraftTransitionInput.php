<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * A draft transition as submitted by the editor. Statuses are referenced by
 * their project-stable code, so the editor never juggles version-scoped
 * identifiers.
 */
final readonly class DraftTransitionInput
{
    /**
     * @param list<DraftRuleInput> $rules
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $fromCode,
        public string $toCode,
        public ?string $permissionCode,
        public bool $isPrimary,
        public int $position,
        public array $rules,
    ) {}
}
