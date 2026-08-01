<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

enum LogicalOperator: string
{
    case And = 'AND';
    case Or = 'OR';
}
