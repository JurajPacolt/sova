<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * The value domain of a field, which decides the operators and literal kinds
 * the semantic layer accepts for it.
 */
enum FieldType
{
    case IssueKey;
    case ProjectCode;
    case IssueTypeCode;
    case Integer;
    case StatusCode;
    case StatusCategory;
    case Priority;
    case Title;
    case Fulltext;
    case User;
    case Workgroup;
    case Label;
    case Date;
    case DateTime;
    case Duration;
}
