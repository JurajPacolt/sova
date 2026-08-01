<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * Stable SovaQL error codes (spec §4.11). The string value is the wire code;
 * {@see self::messageKey()} is the localization key the frontend resolves.
 */
enum QueryErrorCode: string
{
    case SyntaxInvalid = 'QUERY_SYNTAX_INVALID';
    case FieldUnknown = 'QUERY_FIELD_UNKNOWN';
    case FieldNotSupported = 'QUERY_FIELD_NOT_SUPPORTED';
    case OperatorNotAllowed = 'QUERY_OPERATOR_NOT_ALLOWED';
    case ValueInvalid = 'QUERY_VALUE_INVALID';
    case ValueNotAvailable = 'QUERY_VALUE_NOT_AVAILABLE';
    case ValueAmbiguous = 'QUERY_VALUE_AMBIGUOUS';
    case FunctionUnknown = 'QUERY_FUNCTION_UNKNOWN';
    case FunctionArgumentInvalid = 'QUERY_FUNCTION_ARGUMENT_INVALID';
    case TooComplex = 'QUERY_TOO_COMPLEX';
    case TooLong = 'QUERY_TOO_LONG';
    case Timeout = 'QUERY_TIMEOUT';
    case CursorInvalid = 'QUERY_CURSOR_INVALID';

    public function messageKey(): string
    {
        return match ($this) {
            self::SyntaxInvalid => 'query.errors.syntaxInvalid',
            self::FieldUnknown => 'query.errors.fieldUnknown',
            self::FieldNotSupported => 'query.errors.fieldNotSupported',
            self::OperatorNotAllowed => 'query.errors.operatorNotAllowed',
            self::ValueInvalid => 'query.errors.valueInvalid',
            self::ValueNotAvailable => 'query.errors.valueNotAvailable',
            self::ValueAmbiguous => 'query.errors.valueAmbiguous',
            self::FunctionUnknown => 'query.errors.functionUnknown',
            self::FunctionArgumentInvalid => 'query.errors.functionArgumentInvalid',
            self::TooComplex => 'query.errors.tooComplex',
            self::TooLong => 'query.errors.tooLong',
            self::Timeout => 'query.errors.timeout',
            self::CursorInvalid => 'query.errors.cursorInvalid',
        };
    }
}
