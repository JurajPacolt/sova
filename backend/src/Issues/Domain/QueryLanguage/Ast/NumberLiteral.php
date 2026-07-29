<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/**
 * A numeric value. The raw lexeme is kept verbatim so canonical output is
 * stable; {@see self::isInteger()} distinguishes `1` from `1.0`.
 */
final readonly class NumberLiteral implements Value
{
    public function __construct(
        public string $raw,
        private int $start,
        private int $end,
    ) {}

    public function isInteger(): bool
    {
        return !str_contains($this->raw, '.');
    }

    public function toInt(): int
    {
        return (int) $this->raw;
    }

    public function toFloat(): float
    {
        return (float) $this->raw;
    }

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->end;
    }
}
