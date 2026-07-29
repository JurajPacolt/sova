<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * The fields an aggregation may group by.
 *
 * A closed list, for the same reason the compiler keeps a column whitelist: the
 * chosen value becomes part of a `GROUP BY` expression, so it must be selected
 * from a fixed set rather than assembled from whatever the caller stored.
 */
enum AggregationField: string
{
    case Project = 'project';
    case Type = 'type';
    case Status = 'status';
    case StatusCategory = 'statusCategory';
    case Priority = 'priority';
    case Assignee = 'assignee';
    case Group = 'group';
}
