<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\WidgetRegistry;

/**
 * Checks a widget's configuration against the schema of its type and returns
 * the **normalised** form.
 *
 * Two rules run through all of it.
 *
 * It is a whitelist, not a filter: the result is built key by key from what the
 * type declares, so an unknown key never reaches storage, no matter what the
 * client sent. That is what keeps `configuration` free of HTML and free of
 * anything naming a component to run (spec §8.1) — there is no key it could
 * arrive under.
 *
 * And a missing value is a **default**, not a rejection, because a stored
 * configuration must keep working when the schema grows a field. A value that
 * is present but wrong is still rejected, so a typo is never silently ignored.
 */
final class WidgetConfigurationValidator
{
    /** Columns an `issue_list` widget may show, beyond the always-present key. */
    private const array LIST_COLUMNS = [
        'title',
        'project',
        'type',
        'status',
        'statusCategory',
        'priority',
        'assignee',
        'group',
        'parent',
        'resolution',
        'created',
        'updated',
        'resolved',
    ];

    /**
     * A semantic token, never a CSS colour: a widget must not be able to carry
     * arbitrary style into the page (spec §8.2).
     */
    private const array TONES = ['NEUTRAL', 'INFO', 'SUCCESS', 'WARNING', 'DANGER'];

    /**
     * @param array<string, mixed> $raw
     */
    public function validate(WidgetDefinition $definition, array $raw): WidgetConfigurationResult
    {
        return match ($definition->type) {
            WidgetType::IssueCount => $this->count($raw),
            WidgetType::IssueList => $this->list($raw),
            WidgetType::IssueBreakdown => $this->breakdown($definition, $raw),
            WidgetType::IssueMatrix => $this->matrix($definition, $raw),
            WidgetType::IssueTimeSeries => $this->timeSeries($raw),
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function count(array $raw): WidgetConfigurationResult
    {
        $errors = [];
        $tone = $this->choice($raw, 'tone', self::TONES, 'NEUTRAL', $errors);
        $showLink = $this->flag($raw, 'show_link', true, $errors);

        return $errors === [] ? WidgetConfigurationResult::accepted([
            'description' => $this->text($raw, 'description', 160),
            'tone' => $tone,
            'show_link' => $showLink,
        ]) : WidgetConfigurationResult::rejected($errors);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function list(array $raw): WidgetConfigurationResult
    {
        $errors = [];
        $columns = $this->columns($raw, $errors);
        $limit = $this->number($raw, 'limit', 5, 50, 10, $errors);
        $density = $this->choice($raw, 'density', ['COMPACT', 'COMFORTABLE'], 'COMPACT', $errors);

        return $errors === [] ? WidgetConfigurationResult::accepted([
            // The issue key is always shown and cannot be turned off, so it is
            // not part of the stored list at all.
            'columns' => $columns,
            'limit' => $limit,
            'density' => $density,
        ]) : WidgetConfigurationResult::rejected($errors);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function breakdown(
        WidgetDefinition $definition,
        array $raw,
    ): WidgetConfigurationResult {
        $errors = [];
        $groupBy = $this->dimension($definition, $raw, 'group_by', $errors);
        $visualization = $this->choice(
            $raw,
            'visualization',
            ['BAR', 'DONUT', 'TABLE'],
            'BAR',
            $errors,
        );
        $topN = $this->number($raw, 'top_n', 3, 20, 10, $errors);
        $sort = $this->choice($raw, 'sort', ['COUNT', 'NAME'], 'COUNT', $errors);
        $includeEmpty = $this->flag($raw, 'include_empty', true, $errors);

        return $errors === [] && $groupBy !== null ? WidgetConfigurationResult::accepted([
            'group_by' => $groupBy->value,
            'visualization' => $visualization,
            'top_n' => $topN,
            'sort' => $sort,
            'include_empty' => $includeEmpty,
        ]) : WidgetConfigurationResult::rejected($errors);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function matrix(WidgetDefinition $definition, array $raw): WidgetConfigurationResult
    {
        $errors = [];
        $rows = $this->dimension($definition, $raw, 'rows', $errors);
        $columns = $this->dimension($definition, $raw, 'columns', $errors);
        $showTotals = $this->flag($raw, 'show_totals', true, $errors);
        $hideEmpty = $this->flag($raw, 'hide_empty', false, $errors);

        // The same field on both axes would produce a diagonal and nothing
        // else — a table that can only ever say what its own labels say.
        if ($rows !== null && $rows === $columns) {
            $errors['columns'][] = 'The two axes must use different fields.';
        }

        return $errors === [] && $rows !== null && $columns !== null
            ? WidgetConfigurationResult::accepted([
                'rows' => $rows->value,
                'columns' => $columns->value,
                'show_totals' => $showTotals,
                'hide_empty' => $hideEmpty,
            ])
            : WidgetConfigurationResult::rejected($errors);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function timeSeries(array $raw): WidgetConfigurationResult
    {
        $errors = [];
        // `CLOSED` is absent until issues carry a `closed_at`: offering an event
        // the server cannot compute would be a promise it cannot keep.
        $event = $this->choice($raw, 'event', ['CREATED', 'RESOLVED'], 'CREATED', $errors);
        $bucket = $this->choice($raw, 'bucket', ['DAY', 'WEEK', 'MONTH'], 'DAY', $errors);
        $visualization = $this->choice($raw, 'visualization', ['LINE', 'BAR'], 'LINE', $errors);
        $compare = $this->flag($raw, 'compare_created_resolved', false, $errors);
        $range = $raw['range_days'] ?? null;
        $rangeDays = 30;

        if ($range !== null) {
            $candidate = is_int($range) ? $range : (is_string($range) && ctype_digit($range)
                ? (int) $range
                : null);

            // A closed set rather than a range: an arbitrary window would let a
            // widget ask for an unbounded scan.
            if ($candidate === null || !in_array($candidate, [7, 30, 90, 365], true)) {
                $errors['range_days'][] = 'The range must be 7, 30, 90 or 365 days.';
            } else {
                $rangeDays = $candidate;
            }
        }

        return $errors === [] ? WidgetConfigurationResult::accepted([
            'event' => $event,
            'bucket' => $bucket,
            'range_days' => $rangeDays,
            'visualization' => $visualization,
            'compare_created_resolved' => $compare,
        ]) : WidgetConfigurationResult::rejected($errors);
    }

    /**
     * @param array<string, mixed>        $raw
     * @param array<string, list<string>> $errors
     *
     * @return list<string>
     */
    private function columns(array $raw, array &$errors): array
    {
        $value = $raw['columns'] ?? null;

        if ($value === null) {
            return ['title', 'status', 'priority'];
        }

        if (!is_array($value)) {
            $errors['columns'][] = 'Choose between 3 and 10 columns.';

            return [];
        }

        $columns = [];

        foreach ($value as $column) {
            if (!is_string($column) || !in_array($column, self::LIST_COLUMNS, true)) {
                $errors['columns'][] = 'That column cannot be shown.';

                return [];
            }

            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }

        if (count($columns) < 3 || count($columns) > 10) {
            $errors['columns'][] = 'Choose between 3 and 10 columns.';
        }

        return $columns;
    }

    /**
     * @param array<string, mixed>        $raw
     * @param array<string, list<string>> $errors
     */
    private function dimension(
        WidgetDefinition $definition,
        array $raw,
        string $field,
        array &$errors,
    ): ?WidgetDimension {
        $value = $raw[$field] ?? null;

        if (!is_string($value)) {
            $errors[$field][] = 'Choose a field to group by.';

            return null;
        }

        $dimension = WidgetDimension::tryFrom($value);

        if ($dimension === null || !in_array($dimension, $definition->dimensions, true)) {
            $errors[$field][] = 'That field cannot be used for grouping.';

            return null;
        }

        return $dimension;
    }

    /**
     * @param array<string, mixed>        $raw
     * @param list<string>                $allowed
     * @param array<string, list<string>> $errors
     */
    private function choice(
        array $raw,
        string $field,
        array $allowed,
        string $default,
        array &$errors,
    ): string {
        $value = $raw[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $errors[$field][] = 'That value is not one of the allowed options.';

            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>        $raw
     * @param array<string, list<string>> $errors
     */
    private function number(
        array $raw,
        string $field,
        int $minimum,
        int $maximum,
        int $default,
        array &$errors,
    ): int {
        $value = $raw[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        $candidate = is_int($value) ? $value : (is_string($value) && ctype_digit($value)
            ? (int) $value
            : null);

        if ($candidate === null || $candidate < $minimum || $candidate > $maximum) {
            $errors[$field][] = sprintf('Choose a number between %d and %d.', $minimum, $maximum);

            return $default;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed>        $raw
     * @param array<string, list<string>> $errors
     */
    private function flag(array $raw, string $field, bool $default, array &$errors): bool
    {
        $value = $raw[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_bool($value)) {
            $errors[$field][] = 'That value must be true or false.';

            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function text(array $raw, string $field, int $length): string
    {
        $value = $raw[$field] ?? null;

        // Plain text only, and length-capped. It is rendered as text by the
        // client, never as markup.
        return is_string($value) ? mb_substr(trim($value), 0, $length) : '';
    }
}
