<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

/**
 * The three levels the first implementation supports: an epic groups standard
 * issues, and a sub-task refines exactly one standard issue.
 */
enum HierarchyLevel: int
{
    case Epic = 1;
    case Standard = 0;
    case Subtask = -1;

    /** Whether an issue of this level may hang under the given parent level. */
    public function acceptsParent(?self $parent): bool
    {
        return match ($this) {
            self::Epic => $parent === null,
            self::Standard => $parent === null || $parent === self::Epic,
            self::Subtask => $parent === self::Standard,
        };
    }

    public function requiresParent(): bool
    {
        return $this === self::Subtask;
    }
}
