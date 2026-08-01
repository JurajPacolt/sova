<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetType;
use Sova\Issues\Application\Search\AggregationField;
use Sova\Issues\Application\Search\IssueAggregationService;
use Sova\Issues\Application\Search\IssueSearchService;
use Sova\Issues\Application\Search\TimeSeriesBucket;
use Sova\Issues\Application\Search\TimeSeriesEvent;
use Sova\SavedQueries\Application\SavedQueryService;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

/**
 * Loads what one widget shows.
 *
 * The widget is only ever a *pointer*: it names a saved query and how to
 * summarise it. The query is then run **as the caller**, through the same
 * public issue services as a manual search — so a shared dashboard shows each
 * person the numbers their own `issue.view` scope justifies, and a widget can
 * never become a way to read past it.
 *
 * The saved query is re-read on every load rather than cached on the widget: a
 * grant can be withdrawn or the query archived between two refreshes, and the
 * widget must find that out (spec §13).
 */
final readonly class WidgetDataService
{
    public function __construct(
        private WidgetService $widgets,
        private SavedQueryService $savedQueries,
        private IssueSearchService $search,
        private IssueAggregationService $aggregation,
    ) {}

    /**
     * @return array<string, mixed> the payload for this widget's type
     */
    public function load(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        string $membershipId,
    ): array {
        $widget = $this->widgets->get($tenantId, $dashboardId, $widgetId, $membershipId);
        $type = WidgetType::tryFrom($widget->typeKey);

        if ($type === null) {
            // An unknown type is not guessed at. The dashboard shows "widget
            // unavailable"; it does not get somebody else's chart.
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WIDGET_CONFIGURATION_INVALID',
                'That widget type is not available.',
            );
        }

        $query = $this->sourceQuery($subject, $tenantId, $widget, $membershipId);

        return match ($type) {
            WidgetType::IssueCount => $this->count($subject, $tenantId, $query),
            WidgetType::IssueList => $this->list($subject, $tenantId, $query, $widget),
            WidgetType::IssueBreakdown => $this->breakdown($subject, $tenantId, $query, $widget),
            WidgetType::IssueMatrix => $this->matrix($subject, $tenantId, $query, $widget),
            WidgetType::IssueTimeSeries => $this->timeSeries($subject, $tenantId, $query, $widget),
        };
    }

    /**
     * The canonical text of the widget's source, read fresh and through the
     * caller's own reach.
     */
    private function sourceQuery(
        AuthorizationSubject $subject,
        string $tenantId,
        DashboardWidget $widget,
        string $membershipId,
    ): string {
        try {
            $savedQuery = $this->savedQueries->get(
                $subject,
                $tenantId,
                $widget->savedQueryId,
                $membershipId,
            );
        } catch (DomainProblemException) {
            throw $this->sourceGone();
        }

        if ($savedQuery->archived) {
            throw $this->sourceGone();
        }

        return $savedQuery->canonicalQuery;
    }

    /**
     * @return array<string, mixed>
     */
    private function count(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
    ): array {
        return ['count' => $this->aggregation->count($subject, $tenantId, $query)];
    }

    /**
     * A list widget is an ordinary first page of the search. Its order is the
     * saved query's `ORDER BY` — the widget never sorts by something else
     * behind the author's back (spec §8.2).
     *
     * @return array<string, mixed>
     */
    private function list(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        DashboardWidget $widget,
    ): array {
        $limit = $widget->configuration['limit'] ?? 10;
        $outcome = $this->search->search(
            $subject,
            $tenantId,
            $query,
            is_int($limit) ? $limit : 10,
            null,
        );

        $issues = [];

        foreach ($outcome->items as $issue) {
            $issues[] = [
                'id' => $issue->id,
                'key' => $issue->key,
                'title' => $issue->title,
                'project_code' => $issue->projectCode,
                'issue_type_name' => $issue->issueTypeName,
                'status_name' => $issue->statusName,
                'status_category' => $issue->statusCategory,
                'priority' => $issue->priority,
                'assignee_display_name' => $issue->assigneeDisplayName,
                'updated_at' => $issue->updatedAt->format(DATE_ATOM),
            ];
        }

        return ['issues' => $issues];
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdown(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        DashboardWidget $widget,
    ): array {
        $buckets = $this->aggregation->breakdown(
            $subject,
            $tenantId,
            $query,
            $this->field($widget, 'group_by'),
            $this->positive($widget, 'top_n', 10),
            $widget->configuration['include_empty'] !== false,
            ($widget->configuration['sort'] ?? 'COUNT') === 'NAME',
        );

        return [
            'buckets' => array_map(
                static fn($bucket): array => [
                    'key' => $bucket->key,
                    'label' => $bucket->label,
                    'count' => $bucket->count,
                ],
                $buckets,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matrix(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        DashboardWidget $widget,
    ): array {
        $cells = $this->aggregation->matrix(
            $subject,
            $tenantId,
            $query,
            $this->field($widget, 'rows'),
            $this->field($widget, 'columns'),
            // Twenty values per axis (spec §8.2).
            20,
        );

        return [
            'cells' => array_map(
                static fn($cell): array => [
                    'row_key' => $cell->rowKey,
                    'row_label' => $cell->rowLabel,
                    'column_key' => $cell->columnKey,
                    'column_label' => $cell->columnLabel,
                    'count' => $cell->count,
                ],
                $cells,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeSeries(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        DashboardWidget $widget,
    ): array {
        $bucket = TimeSeriesBucket::tryFrom(
            is_string($widget->configuration['bucket'] ?? null)
                ? $widget->configuration['bucket']
                : 'DAY',
        ) ?? TimeSeriesBucket::Day;

        $range = $this->positive($widget, 'range_days', 30);
        $events = [
            TimeSeriesEvent::tryFrom(
                is_string($widget->configuration['event'] ?? null)
                    ? $widget->configuration['event']
                    : 'CREATED',
            ) ?? TimeSeriesEvent::Created,
        ];

        if (($widget->configuration['compare_created_resolved'] ?? false) === true) {
            $events = [TimeSeriesEvent::Created, TimeSeriesEvent::Resolved];
        }

        $series = [];

        foreach ($events as $event) {
            $points = $this->aggregation->timeSeries(
                $subject,
                $tenantId,
                $query,
                $event,
                $bucket,
                $range,
            );

            $series[] = [
                'event' => $event->value,
                'points' => array_map(
                    static fn($point): array => [
                        'bucket' => $point->bucket->format(DATE_ATOM),
                        'count' => $point->count,
                    ],
                    $points,
                ),
            ];
        }

        // The series counts events on the issues the query selects **today**;
        // it is not an immutable historical snapshot, and the UI says so.
        return ['series' => $series];
    }

    private function field(DashboardWidget $widget, string $key): AggregationField
    {
        $value = $widget->configuration[$key] ?? null;
        $field = is_string($value) ? AggregationField::tryFrom($value) : null;

        if ($field === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WIDGET_CONFIGURATION_INVALID',
                'The widget configuration does not match its type.',
                [$key => ['That field cannot be used for grouping.']],
            );
        }

        return $field;
    }

    private function positive(DashboardWidget $widget, string $key, int $default): int
    {
        $value = $widget->configuration[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function sourceGone(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'WIDGET_DATA_SOURCE_NOT_FOUND',
            'The saved query behind this widget is no longer available.',
        );
    }
}
