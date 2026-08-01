<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * Lexical token kinds. Keywords (AND, OR, IN, IS, EMPTY, ORDER, ...) are not
 * distinguished here: they surface as {@see self::Identifier} and the parser
 * interprets them case-insensitively, so a field or value that collides with a
 * keyword can still be written when quoted.
 */
enum TokenType
{
    case Identifier;
    case String;
    case Number;
    case OpenParen;
    case CloseParen;
    case Comma;
    case Dot;
    case Equals;
    case NotEquals;
    case GreaterThan;
    case GreaterOrEqual;
    case LessThan;
    case LessOrEqual;
    case Matches;
    case NotMatches;
    case EndOfInput;
}
