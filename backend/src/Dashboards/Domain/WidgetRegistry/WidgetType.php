<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\WidgetRegistry;

/**
 * The widget types this deployment knows.
 *
 * The stored `type_key` is matched against this enum and nothing else. An
 * unknown key is never guessed at or reinterpreted as a neighbouring type — the
 * dashboard shows "widget unavailable" and offers to remove it, which is the
 * only safe answer when the application no longer knows what the stored
 * configuration meant (spec §8.3).
 */
enum WidgetType: string
{
    case IssueCount = 'issue_count';
    case IssueList = 'issue_list';
    case IssueBreakdown = 'issue_breakdown';
    case IssueMatrix = 'issue_matrix';
    case IssueTimeSeries = 'issue_time_series';
}
