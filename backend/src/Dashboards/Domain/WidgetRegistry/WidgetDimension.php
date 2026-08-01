<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\WidgetRegistry;

/**
 * The dimensions an aggregating widget may group by.
 *
 * This is a closed list on purpose. The value stored in a widget's
 * configuration eventually becomes a `GROUP BY` expression, so it must be
 * chosen from a fixed set rather than accepted as a field name — the same
 * whitelist discipline the query compiler applies to columns.
 */
enum WidgetDimension: string
{
    case Project = 'project';
    case Type = 'type';
    case Status = 'status';
    case StatusCategory = 'statusCategory';
    case Priority = 'priority';
    case Assignee = 'assignee';
    case Group = 'group';
}
