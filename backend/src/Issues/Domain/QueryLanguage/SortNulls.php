<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

enum SortNulls: string
{
    case First = 'FIRST';
    case Last = 'LAST';
}
