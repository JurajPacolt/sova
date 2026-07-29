<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * A single lexical token. Positions are UTF-8 codepoint offsets into the
 * original query text; {@see $start} is inclusive and {@see $end} is exclusive,
 * matching the ranges the validate endpoint returns to the editor.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $start,
        public int $end,
        /**
         * Decoded value for a {@see TokenType::String} (escape sequences
         * resolved); the raw lexeme otherwise.
         */
        public string $value,
    ) {}

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }

    /** Case-insensitive keyword comparison against the raw lexeme. */
    public function isKeyword(string $keyword): bool
    {
        return $this->type === TokenType::Identifier
            && strcasecmp($this->lexeme, $keyword) === 0;
    }
}
