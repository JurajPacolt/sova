<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

enum TimeSeriesBucket: string
{
    case Day = 'DAY';
    case Week = 'WEEK';
    case Month = 'MONTH';
}
