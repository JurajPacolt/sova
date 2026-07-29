<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\WidgetRegistry;

/**
 * The catalogue of widget types the application ships.
 *
 * It is a **data** registry: keys, versions, sizes and dimensions. There is no
 * component name and no executable reference anywhere in it, and none may ever
 * be stored in a widget's configuration either — the frontend maps a `type_key`
 * to a component itself, so a stored string can never name something to run.
 */
final class WidgetRegistry
{
    /**
     * @return list<WidgetDefinition>
     */
    public function definitions(): array
    {
        $aggregable = [
            WidgetDimension::Project,
            WidgetDimension::Type,
            WidgetDimension::Status,
            WidgetDimension::StatusCategory,
            WidgetDimension::Priority,
            WidgetDimension::Assignee,
            WidgetDimension::Group,
        ];

        return [
            new WidgetDefinition(
                WidgetType::IssueCount,
                1,
                'widget.type.count.label',
                'widget.type.count.description',
                minWidth: 2,
                minHeight: 1,
                defaultWidth: 3,
                defaultHeight: 2,
                maxWidth: 6,
                maxHeight: 3,
                dimensions: [],
            ),
            new WidgetDefinition(
                WidgetType::IssueList,
                1,
                'widget.type.list.label',
                'widget.type.list.description',
                minWidth: 4,
                minHeight: 2,
                defaultWidth: 6,
                defaultHeight: 4,
                maxWidth: 12,
                maxHeight: 8,
                dimensions: [],
            ),
            new WidgetDefinition(
                WidgetType::IssueBreakdown,
                1,
                'widget.type.breakdown.label',
                'widget.type.breakdown.description',
                minWidth: 3,
                minHeight: 2,
                defaultWidth: 4,
                defaultHeight: 4,
                maxWidth: 12,
                maxHeight: 8,
                dimensions: $aggregable,
            ),
            new WidgetDefinition(
                WidgetType::IssueMatrix,
                1,
                'widget.type.matrix.label',
                'widget.type.matrix.description',
                minWidth: 4,
                minHeight: 3,
                defaultWidth: 6,
                defaultHeight: 5,
                maxWidth: 12,
                maxHeight: 10,
                dimensions: $aggregable,
            ),
            new WidgetDefinition(
                WidgetType::IssueTimeSeries,
                1,
                'widget.type.timeSeries.label',
                'widget.type.timeSeries.description',
                minWidth: 4,
                minHeight: 2,
                defaultWidth: 6,
                defaultHeight: 4,
                maxWidth: 12,
                maxHeight: 8,
                dimensions: [],
            ),
        ];
    }

    public function find(string $typeKey): ?WidgetDefinition
    {
        $type = WidgetType::tryFrom($typeKey);

        if ($type === null) {
            return null;
        }

        foreach ($this->definitions() as $definition) {
            if ($definition->type === $type) {
                return $definition;
            }
        }

        return null;
    }
}
