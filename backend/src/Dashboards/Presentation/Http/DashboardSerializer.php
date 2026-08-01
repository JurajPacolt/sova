<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http;

use Sova\Dashboards\Application\Dashboard;

final readonly class DashboardSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(Dashboard $dashboard, bool $active): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'position' => $dashboard->position,
            'is_default' => $dashboard->isDefault,
            // Which one the caller last opened. A preference of theirs, not a
            // property of the dashboard.
            'is_active' => $active,
            'widget_count' => $dashboard->widgetCount,
            'version' => $dashboard->version,
            'created_at' => $dashboard->createdAt->format(DATE_ATOM),
            'updated_at' => $dashboard->updatedAt->format(DATE_ATOM),
        ];
    }
}
