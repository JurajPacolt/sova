<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * Turns SovaQL text into a flat token stream. It is deliberately dumb about
 * meaning: keywords stay identifiers and `SOVA-123` is a single identifier, so
 * the parser and semantic layer own all interpretation. Positions are UTF-8
 * codepoint offsets to keep editor highlighting language-agnostic.
 */
final class Lexer
{
    /** @var list<string> */
    private array $chars;

    private int $length;

    public function __construct(string $text)
    {
        $split = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $this->chars = $split === false ? [] : $split;
        $this->length = count($this->chars);
    }

    /**
     * @return list<Token>
     *
     * @throws SovaQlSyntaxException
     */
    public function tokenize(): array
    {
        $tokens = [];
        $i = 0;

        while ($i < $this->length) {
            $char = $this->chars[$i];

            if ($this->isWhitespace($char)) {
                $i++;

                continue;
            }

            if ($char === '"') {
                $tokens[] = $this->readString($i);
                $i = $tokens[count($tokens) - 1]->end;

                continue;
            }

            if ($this->startsNumber($i)) {
                $tokens[] = $this->readNumber($i);
                $i = $tokens[count($tokens) - 1]->end;

                continue;
            }

            if ($this->isIdentifierStart($char)) {
                $tokens[] = $this->readIdentifier($i);
                $i = $tokens[count($tokens) - 1]->end;

                continue;
            }

            $tokens[] = $this->readSymbol($i);
            $i = $tokens[count($tokens) - 1]->end;
        }

        $tokens[] = new Token(TokenType::EndOfInput, '', $this->length, $this->length, '');

        return $tokens;
    }

    private function readString(int $start): Token
    {
        $i = $start + 1;
        $value = '';

        while ($i < $this->length) {
            $char = $this->chars[$i];

            if ($char === '\\') {
                $next = $this->chars[$i + 1] ?? null;

                if ($next !== '"' && $next !== '\\') {
                    throw $this->syntaxError($i, $i + 1);
                }

                $value .= $next;
                $i += 2;

                continue;
            }

            if ($char === '"') {
                $end = $i + 1;

                return new Token(
                    TokenType::String,
                    implode('', array_slice($this->chars, $start, $end - $start)),
                    $start,
                    $end,
                    $value,
                );
            }

            $value .= $char;
            $i++;
        }

        throw $this->syntaxError($start, $this->length);
    }

    private function readNumber(int $start): Token
    {
        $i = $start;

        if ($this->chars[$i] === '+' || $this->chars[$i] === '-') {
            $i++;
        }

        while ($i < $this->length && ctype_digit($this->chars[$i])) {
            $i++;
        }

        if (
            $i + 1 < $this->length
            && $this->chars[$i] === '.'
            && ctype_digit($this->chars[$i + 1])
        ) {
            $i++;

            while ($i < $this->length && ctype_digit($this->chars[$i])) {
                $i++;
            }
        }

        $lexeme = implode('', array_slice($this->chars, $start, $i - $start));

        return new Token(TokenType::Number, $lexeme, $start, $i, $lexeme);
    }

    private function readIdentifier(int $start): Token
    {
        $i = $start + 1;

        while ($i < $this->length && $this->isIdentifierPart($this->chars[$i])) {
            $i++;
        }

        $lexeme = implode('', array_slice($this->chars, $start, $i - $start));

        return new Token(TokenType::Identifier, $lexeme, $start, $i, $lexeme);
    }

    private function readSymbol(int $start): Token
    {
        $char = $this->chars[$start];
        $next = $this->chars[$start + 1] ?? null;

        return match ($char) {
            '(' => new Token(TokenType::OpenParen, '(', $start, $start + 1, '('),
            ')' => new Token(TokenType::CloseParen, ')', $start, $start + 1, ')'),
            ',' => new Token(TokenType::Comma, ',', $start, $start + 1, ','),
            '.' => new Token(TokenType::Dot, '.', $start, $start + 1, '.'),
            '=' => new Token(TokenType::Equals, '=', $start, $start + 1, '='),
            '~' => new Token(TokenType::Matches, '~', $start, $start + 1, '~'),
            '>' => $next === '='
                ? new Token(TokenType::GreaterOrEqual, '>=', $start, $start + 2, '>=')
                : new Token(TokenType::GreaterThan, '>', $start, $start + 1, '>'),
            '<' => $next === '='
                ? new Token(TokenType::LessOrEqual, '<=', $start, $start + 2, '<=')
                : new Token(TokenType::LessThan, '<', $start, $start + 1, '<'),
            '!' => $this->readBangOperator($start, $next),
            default => throw $this->syntaxError($start, $start + 1),
        };
    }

    private function readBangOperator(int $start, ?string $next): Token
    {
        return match ($next) {
            '=' => new Token(TokenType::NotEquals, '!=', $start, $start + 2, '!='),
            '~' => new Token(TokenType::NotMatches, '!~', $start, $start + 2, '!~'),
            default => throw $this->syntaxError($start, $start + 1),
        };
    }

    private function startsNumber(int $i): bool
    {
        $char = $this->chars[$i];

        if (ctype_digit($char)) {
            return true;
        }

        if ($char !== '+' && $char !== '-') {
            return false;
        }

        $next = $this->chars[$i + 1] ?? '';

        return ctype_digit($next);
    }

    private function isIdentifierStart(string $char): bool
    {
        return $char === '_' || ctype_alpha($char);
    }

    private function isIdentifierPart(string $char): bool
    {
        return $char === '_' || $char === '-' || ctype_alnum($char);
    }

    private function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }

    private function syntaxError(int $start, int $end): SovaQlSyntaxException
    {
        return new SovaQlSyntaxException(
            new QueryError(QueryErrorCode::SyntaxInvalid, $start, $end),
        );
    }
}
