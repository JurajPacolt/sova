<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\Link;

/**
 * The link kinds of spec §6.9, minus parent/child: hierarchy already lives on
 * `issues.parent_issue_id` with its own level rules, and storing it a second
 * time here would create two sources of truth that could disagree.
 *
 * A link is stored once, on the source issue, and read from both ends. The
 * inverse label is derived rather than stored, which is what makes the two
 * directions incapable of disagreeing.
 */
enum IssueLinkType: string
{
    case Blocks = 'BLOCKS';
    case RelatesTo = 'RELATES_TO';
    case Duplicates = 'DUPLICATES';

    /**
     * How the link reads from the other issue's side.
     */
    public function inverseLabel(): string
    {
        return match ($this) {
            self::Blocks => 'IS_BLOCKED_BY',
            self::RelatesTo => 'RELATES_TO',
            self::Duplicates => 'IS_DUPLICATED_BY',
        };
    }

    /**
     * A symmetric type means the same thing in both directions, so recording it
     * twice is redundant rather than contradictory. Either way the mirror pair
     * is refused; this only changes how the refusal is explained.
     */
    public function isSymmetric(): bool
    {
        return $this === self::RelatesTo;
    }
}
