<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\Template;

use Sova\Dashboards\Domain\WidgetRegistry\WidgetType;

/**
 * The dashboard every member starts with (spec §7.5).
 *
 * It is a **versioned data manifest, not a script**: queries, widget presets
 * and a layout. Nothing here is executed, and nothing here is a reference —
 * the template is *copied* into private queries and widgets owned by the member
 * who receives it, exactly as a new project copies `DefaultTemplate` rather
 * than linking to it. Changing these constants later leaves existing dashboards
 * untouched.
 *
 * Two rules bind the content. It may name **no tenant, no project and no
 * person**: whose issues these are is expressed with `currentUser()`, so the
 * same manifest is right for everybody and carries no identifier that could
 * belong to somebody else. And it may only use what the language actually
 * supports today — the recommended "overdue" tile in the specification needs
 * `due`, which SovaQL still reports as unsupported, so it is replaced by a
 * question the server can answer rather than shipped as a query that would fail
 * the moment it ran.
 */
final class StarterTemplate
{
    /**
     * Bumping this is how a later manifest becomes distinguishable from this
     * one; it is stored nowhere, because a provisioned dashboard is a copy the
     * member owns and may change beyond recognition.
     */
    public const int VERSION = 1;

    public const string DASHBOARD_NAME = 'My work';

    /**
     * @return list<TemplateQuery>
     */
    public static function queries(): array
    {
        return [
            new TemplateQuery(
                'assigned-to-me',
                'Assigned to me',
                'Open issues assigned to me, most important first.',
                'assignee = currentUser() AND statusCategory != DONE'
                    . ' ORDER BY priority DESC, updated DESC',
                ['title', 'status', 'priority', 'updated'],
            ),
            new TemplateQuery(
                'reported-by-me',
                'Reported by me',
                'Issues I reported that are still open.',
                'reporter = currentUser() AND statusCategory != DONE',
                ['title', 'status', 'assignee'],
            ),
            new TemplateQuery(
                'recently-updated',
                'Recently updated',
                'Open issues across my projects, most recently changed first.',
                'statusCategory != DONE ORDER BY updated DESC',
                ['title', 'project', 'status', 'updated'],
            ),
            new TemplateQuery(
                'open-issues',
                'Open issues',
                'Everything still open that I can see.',
                'statusCategory != DONE',
                ['title', 'project', 'status'],
            ),
        ];
    }

    /**
     * The 12-column arrangement the widgets land in. It is validated as a whole
     * like any other layout, so a mistake here fails provisioning loudly
     * instead of storing overlapping widgets.
     *
     * @return list<TemplateWidget>
     */
    public static function widgets(): array
    {
        return [
            new TemplateWidget(
                'assigned-to-me',
                WidgetType::IssueList,
                'Assigned to me',
                [
                    'columns' => ['title', 'status', 'priority'],
                    'limit' => 10,
                    'density' => 'COMPACT',
                ],
                x: 0,
                y: 0,
                width: 8,
                height: 4,
            ),
            new TemplateWidget(
                'reported-by-me',
                WidgetType::IssueCount,
                'Reported by me',
                [
                    'description' => 'Still open',
                    'tone' => 'INFO',
                    'show_link' => true,
                ],
                x: 8,
                y: 0,
                width: 4,
                height: 2,
            ),
            new TemplateWidget(
                'open-issues',
                WidgetType::IssueBreakdown,
                'By status',
                [
                    'group_by' => 'status',
                    'visualization' => 'BAR',
                    'top_n' => 10,
                    'sort' => 'COUNT',
                    'include_empty' => true,
                ],
                x: 8,
                y: 2,
                width: 4,
                height: 4,
            ),
            new TemplateWidget(
                'recently-updated',
                WidgetType::IssueList,
                'Recently updated',
                [
                    'columns' => ['title', 'project', 'updated'],
                    'limit' => 10,
                    'density' => 'COMPACT',
                ],
                x: 0,
                y: 4,
                width: 8,
                height: 4,
            ),
        ];
    }
}
