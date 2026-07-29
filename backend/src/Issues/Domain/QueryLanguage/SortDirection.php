<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

enum SortDirection: string
{
    case Ascending = 'ASC';
    case Descending = 'DESC';
}
