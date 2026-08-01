<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

enum ComparisonOperator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case GreaterThan = '>';
    case GreaterOrEqual = '>=';
    case LessThan = '<';
    case LessOrEqual = '<=';
    case Matches = '~';
    case NotMatches = '!~';

    public function isOrdering(): bool
    {
        return match ($this) {
            self::GreaterThan,
            self::GreaterOrEqual,
            self::LessThan,
            self::LessOrEqual => true,
            default => false,
        };
    }

    public function isFulltext(): bool
    {
        return $this === self::Matches || $this === self::NotMatches;
    }

    public function isEquality(): bool
    {
        return $this === self::Equals || $this === self::NotEquals;
    }
}
