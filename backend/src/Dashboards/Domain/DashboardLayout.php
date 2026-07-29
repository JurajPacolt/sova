<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain;

use Sova\Dashboards\Domain\WidgetRegistry\WidgetDefinition;

/**
 * Validates a whole arrangement at once.
 *
 * The database already refuses a widget that starts outside the grid or reaches
 * past its right edge, but overlap and the per-type minimum size cannot be
 * stated as a readable constraint — a `CHECK` cannot see the other rows. So the
 * rule lives here, and it is checked against **the complete set** rather than
 * one widget at a time: moving two widgets past each other is legal only when
 * both moves are considered together.
 */
final class DashboardLayout
{
    /** Thirty widgets per dashboard (spec §7.4). */
    public const int MAX_WIDGETS = 30;

    /**
     * @param list<WidgetPlacement>              $placements
     * @param array<string, WidgetDefinition>    $definitions keyed by widget id
     *
     * @return array<string, list<string>> empty when the arrangement is sound
     */
    public static function validate(array $placements, array $definitions): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if (count($placements) > self::MAX_WIDGETS) {
            $errors['widgets'][] = sprintf(
                'A dashboard holds at most %d widgets.',
                self::MAX_WIDGETS,
            );
        }

        $seen = [];

        foreach ($placements as $placement) {
            if (isset($seen[$placement->widgetId])) {
                $errors['widgets'][] = 'Each widget may appear once in the layout.';

                continue;
            }

            $seen[$placement->widgetId] = true;
            $definition = $definitions[$placement->widgetId] ?? null;

            if ($definition === null) {
                $errors['widgets'][] = 'The layout names a widget of this dashboard that is unknown.';

                continue;
            }

            self::checkSize($placement, $definition, $errors);
        }

        foreach ($placements as $index => $placement) {
            foreach (array_slice($placements, $index + 1) as $other) {
                if ($placement->overlaps($other)) {
                    $errors['widgets'][] = 'Two widgets overlap.';

                    return $errors;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function checkSize(
        WidgetPlacement $placement,
        WidgetDefinition $definition,
        array &$errors,
    ): void {
        if ($placement->x < 0 || $placement->y < 0) {
            $errors['widgets'][] = 'A widget cannot start outside the grid.';

            return;
        }

        if ($placement->x + $placement->width > 12) {
            $errors['widgets'][] = 'A widget cannot reach past the right edge of the grid.';

            return;
        }

        if (
            $placement->width < $definition->minWidth
            || $placement->height < $definition->minHeight
        ) {
            $errors['widgets'][] = 'A widget is smaller than its type allows.';

            return;
        }

        if (
            $placement->width > $definition->maxWidth
            || $placement->height > $definition->maxHeight
        ) {
            $errors['widgets'][] = 'A widget is larger than its type allows.';
        }
    }
}
