<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * Which timestamp a time series counts. Each maps to one fixed column; there is
 * no path for a caller-supplied column name to get here.
 *
 * `CLOSED` is missing deliberately. Issues have no `closed_at` column yet — the
 * SovaQL field catalog reports `closed` as unsupported for the same reason —
 * and offering an event the server cannot compute would be a promise it cannot
 * keep. It lights up in the phase that adds the column, without a change to
 * anything else here.
 */
enum TimeSeriesEvent: string
{
    case Created = 'CREATED';
    case Resolved = 'RESOLVED';
}
