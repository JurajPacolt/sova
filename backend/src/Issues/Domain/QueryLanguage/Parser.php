<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use Sova\Issues\Domain\QueryLanguage\Ast\BooleanLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\ComparisonPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\EmptyPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\Expression;
use Sova\Issues\Domain\QueryLanguage\Ast\FieldReference;
use Sova\Issues\Domain\QueryLanguage\Ast\FunctionCall;
use Sova\Issues\Domain\QueryLanguage\Ast\IdentifierValue;
use Sova\Issues\Domain\QueryLanguage\Ast\LogicalExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NotExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NumberLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\Ast\SetPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\SortItem;
use Sova\Issues\Domain\QueryLanguage\Ast\StringLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Value;

/**
 * Recursive-descent parser for SovaQL v1. It enforces the spec precedence
 * (parentheses, then `NOT`, `AND`, `OR`, then `ORDER BY`) and bounds nesting so
 * a pathological query cannot exhaust the stack. It never touches the database
 * or the field catalog: unknown fields and bad value types are the semantic
 * layer's concern, so this stage rejects only structural problems.
 */
final class Parser
{
    private const array RESERVED = [
        'AND', 'OR', 'NOT', 'IN', 'IS', 'EMPTY',
        'ORDER', 'BY', 'ASC', 'DESC', 'NULLS', 'FIRST', 'LAST',
    ];

    private int $position = 0;

    private int $depth = 0;

    /**
     * @param list<Token> $tokens
     */
    public function __construct(
        private readonly array $tokens,
        private readonly int $maxParenDepth = 10,
    ) {}

    /**
     * @throws SovaQlSyntaxException
     */
    public function parse(): Query
    {
        $filter = null;

        if (!$this->atEnd() && !$this->currentIsKeyword('ORDER')) {
            $filter = $this->parseExpression();
        }

        $sort = $this->currentIsKeyword('ORDER') ? $this->parseOrderBy() : [];

        if (!$this->atEnd()) {
            throw $this->syntaxError($this->current());
        }

        return new Query($filter, $sort);
    }

    private function parseExpression(): Expression
    {
        return $this->parseOr();
    }

    private function parseOr(): Expression
    {
        $left = $this->parseAnd();

        while ($this->currentIsKeyword('OR')) {
            $this->advance();
            $right = $this->parseAnd();
            $left = new LogicalExpression(LogicalOperator::Or, $left, $right);
        }

        return $left;
    }

    private function parseAnd(): Expression
    {
        $left = $this->parseNot();

        while ($this->currentIsKeyword('AND')) {
            $this->advance();
            $right = $this->parseNot();
            $left = new LogicalExpression(LogicalOperator::And, $left, $right);
        }

        return $left;
    }

    private function parseNot(): Expression
    {
        if ($this->currentIsKeyword('NOT')) {
            $token = $this->advance();

            return new NotExpression($this->parseNot(), $token->start);
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Expression
    {
        if ($this->current()->is(TokenType::OpenParen)) {
            $open = $this->advance();
            $this->depth++;

            if ($this->depth > $this->maxParenDepth) {
                throw new SovaQlSyntaxException(new QueryError(
                    QueryErrorCode::TooComplex,
                    $open->start,
                    $open->end,
                    ['limit' => $this->maxParenDepth],
                ));
            }

            $expression = $this->parseExpression();
            $this->expect(TokenType::CloseParen);
            $this->depth--;

            return $expression;
        }

        return $this->parsePredicate();
    }

    private function parsePredicate(): Expression
    {
        $field = $this->parseField();
        $token = $this->current();

        $operator = $this->comparisonOperator($token);

        if ($operator !== null) {
            $this->advance();

            return new ComparisonPredicate(
                $field,
                $operator,
                $this->parseValue(),
                $token->start,
                $token->end,
            );
        }

        if ($token->isKeyword('IN')) {
            $this->advance();

            return $this->finishSetPredicate($field, false, $token);
        }

        if ($token->isKeyword('NOT')) {
            $this->advance();
            $in = $this->current();

            if (!$in->isKeyword('IN')) {
                throw $this->syntaxError($in);
            }

            $this->advance();

            return $this->finishSetPredicate($field, true, $token);
        }

        if ($token->isKeyword('IS')) {
            return $this->parseEmptyPredicate($field);
        }

        throw $this->syntaxError($token);
    }

    private function finishSetPredicate(
        FieldReference $field,
        bool $negated,
        Token $operatorToken,
    ): SetPredicate {
        if ($this->current()->is(TokenType::OpenParen)) {
            $this->advance();
            $values = [$this->parseValue()];

            while ($this->current()->is(TokenType::Comma)) {
                $this->advance();
                $values[] = $this->parseValue();
            }

            $close = $this->expect(TokenType::CloseParen);

            return new SetPredicate(
                $field,
                $negated,
                $values,
                null,
                $operatorToken->start,
                $operatorToken->end,
                $close->end,
            );
        }

        $function = $this->parseFunctionValue();

        return new SetPredicate(
            $field,
            $negated,
            [],
            $function,
            $operatorToken->start,
            $operatorToken->end,
            $function->end(),
        );
    }

    private function parseEmptyPredicate(FieldReference $field): EmptyPredicate
    {
        $is = $this->advance();
        $negated = false;

        if ($this->currentIsKeyword('NOT')) {
            $this->advance();
            $negated = true;
        }

        $empty = $this->current();

        if (!$empty->isKeyword('EMPTY')) {
            throw $this->syntaxError($empty);
        }

        $this->advance();

        return new EmptyPredicate($field, $negated, $is->start, $empty->end);
    }

    private function parseField(): FieldReference
    {
        $token = $this->current();

        if (!$token->is(TokenType::Identifier) || $this->isReserved($token)) {
            throw $this->syntaxError($token);
        }

        $this->advance();
        $name = $token->lexeme;
        $end = $token->end;

        if ($this->current()->is(TokenType::Dot)) {
            $this->advance();
            $second = $this->current();

            if (!$second->is(TokenType::Identifier)) {
                throw $this->syntaxError($second);
            }

            $this->advance();
            $name .= '.' . $second->lexeme;
            $end = $second->end;
        }

        return new FieldReference($name, $token->start, $end);
    }

    private function parseValue(): Value
    {
        $token = $this->current();

        if ($token->is(TokenType::String)) {
            $this->advance();

            return new StringLiteral($token->value, $token->start, $token->end);
        }

        if ($token->is(TokenType::Number)) {
            $this->advance();

            return new NumberLiteral($token->lexeme, $token->start, $token->end);
        }

        if ($token->is(TokenType::Identifier)) {
            if ($this->peek()->is(TokenType::OpenParen)) {
                return $this->parseFunctionValue();
            }

            $this->advance();

            if (strcasecmp($token->lexeme, 'true') === 0) {
                return new BooleanLiteral(true, $token->start, $token->end);
            }

            if (strcasecmp($token->lexeme, 'false') === 0) {
                return new BooleanLiteral(false, $token->start, $token->end);
            }

            if ($this->isReserved($token)) {
                throw $this->syntaxError($token);
            }

            return new IdentifierValue($token->lexeme, $token->start, $token->end);
        }

        throw $this->syntaxError($token);
    }

    private function parseFunctionValue(): FunctionCall
    {
        $name = $this->current();

        if (!$name->is(TokenType::Identifier) || $this->isReserved($name)) {
            throw $this->syntaxError($name);
        }

        $this->advance();
        $this->expect(TokenType::OpenParen);
        $arguments = [];

        if (!$this->current()->is(TokenType::CloseParen)) {
            $arguments[] = $this->parseValue();

            while ($this->current()->is(TokenType::Comma)) {
                $this->advance();
                $arguments[] = $this->parseValue();
            }
        }

        $close = $this->expect(TokenType::CloseParen);

        return new FunctionCall($name->lexeme, $arguments, $name->start, $close->end);
    }

    /**
     * @return list<SortItem>
     */
    private function parseOrderBy(): array
    {
        $this->advance();
        $by = $this->current();

        if (!$by->isKeyword('BY')) {
            throw $this->syntaxError($by);
        }

        $this->advance();
        $items = [$this->parseSortItem()];

        while ($this->current()->is(TokenType::Comma)) {
            $this->advance();
            $items[] = $this->parseSortItem();
        }

        return $items;
    }

    private function parseSortItem(): SortItem
    {
        $field = $this->parseField();
        $direction = SortDirection::Ascending;

        if ($this->currentIsKeyword('ASC')) {
            $this->advance();
        } elseif ($this->currentIsKeyword('DESC')) {
            $this->advance();
            $direction = SortDirection::Descending;
        }

        $nulls = null;

        if ($this->currentIsKeyword('NULLS')) {
            $this->advance();
            $placement = $this->current();

            if ($placement->isKeyword('FIRST')) {
                $nulls = SortNulls::First;
            } elseif ($placement->isKeyword('LAST')) {
                $nulls = SortNulls::Last;
            } else {
                throw $this->syntaxError($placement);
            }

            $this->advance();
        }

        return new SortItem($field, $direction, $nulls);
    }

    private function comparisonOperator(Token $token): ?ComparisonOperator
    {
        return match ($token->type) {
            TokenType::Equals => ComparisonOperator::Equals,
            TokenType::NotEquals => ComparisonOperator::NotEquals,
            TokenType::GreaterThan => ComparisonOperator::GreaterThan,
            TokenType::GreaterOrEqual => ComparisonOperator::GreaterOrEqual,
            TokenType::LessThan => ComparisonOperator::LessThan,
            TokenType::LessOrEqual => ComparisonOperator::LessOrEqual,
            TokenType::Matches => ComparisonOperator::Matches,
            TokenType::NotMatches => ComparisonOperator::NotMatches,
            default => null,
        };
    }

    private function isReserved(Token $token): bool
    {
        foreach (self::RESERVED as $keyword) {
            if (strcasecmp($token->lexeme, $keyword) === 0) {
                return true;
            }
        }

        return false;
    }

    private function currentIsKeyword(string $keyword): bool
    {
        return $this->current()->isKeyword($keyword);
    }

    private function current(): Token
    {
        return $this->tokens[$this->position];
    }

    private function peek(): Token
    {
        return $this->tokens[$this->position + 1] ?? $this->tokens[$this->position];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->position];

        if (!$this->atEnd()) {
            $this->position++;
        }

        return $token;
    }

    private function expect(TokenType $type): Token
    {
        $token = $this->current();

        if (!$token->is($type)) {
            throw $this->syntaxError($token);
        }

        return $this->advance();
    }

    private function atEnd(): bool
    {
        return $this->current()->is(TokenType::EndOfInput);
    }

    private function syntaxError(Token $token): SovaQlSyntaxException
    {
        $end = $token->end > $token->start ? $token->end : $token->start + 1;

        return new SovaQlSyntaxException(
            new QueryError(QueryErrorCode::SyntaxInvalid, $token->start, $end),
        );
    }
}
