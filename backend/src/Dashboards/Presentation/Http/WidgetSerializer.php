<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http;

use Sova\Dashboards\Application\DashboardWidget;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetDefinition;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetDimension;

final readonly class WidgetSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(DashboardWidget $widget): array
    {
        return [
            'id' => $widget->id,
            'dashboard_id' => $widget->dashboardId,
            'type_key' => $widget->typeKey,
            // False when this deployment no longer knows the stored type. The
            // row is still returned so the widget can be removed; the server
            // does not guess what its configuration meant.
            'available' => $widget->available,
            'schema_version' => $widget->schemaVersion,
            'title' => $widget->title,
            'saved_query_id' => $widget->savedQueryId,
            'source_name' => $widget->sourceName,
            'source_reachable' => $widget->sourceReachable,
            'configuration' => $widget->configuration,
            'x' => $widget->x,
            'y' => $widget->y,
            'width' => $widget->width,
            'height' => $widget->height,
            'version' => $widget->version,
            'created_at' => $widget->createdAt->format(DATE_ATOM),
            'updated_at' => $widget->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Labels are localisation **keys**, not text: the catalogs own the wording,
     * so no user-facing string is shipped from the server.
     *
     * @return array<string, mixed>
     */
    public function serializeDefinition(WidgetDefinition $definition): array
    {
        return [
            'type_key' => $definition->type->value,
            'schema_version' => $definition->schemaVersion,
            'label_key' => $definition->labelKey,
            'description_key' => $definition->descriptionKey,
            'min_width' => $definition->minWidth,
            'min_height' => $definition->minHeight,
            'default_width' => $definition->defaultWidth,
            'default_height' => $definition->defaultHeight,
            'max_width' => $definition->maxWidth,
            'max_height' => $definition->maxHeight,
            'dimensions' => array_map(
                static fn(WidgetDimension $dimension): string => $dimension->value,
                $definition->dimensions,
            ),
        ];
    }
}
