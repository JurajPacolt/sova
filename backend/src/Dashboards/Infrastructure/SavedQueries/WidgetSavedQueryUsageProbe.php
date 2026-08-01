<?php

declare(strict_types=1);

namespace Sova\Dashboards\Infrastructure\SavedQueries;

use Sova\Dashboards\Application\WidgetRepository;
use Sova\SavedQueries\Application\SavedQueryUsageProbe;

/**
 * Answers the saved-query module's question — "is anything still using this?" —
 * from the widget side, without the saved-query module having to know that
 * dashboards exist.
 */
final readonly class WidgetSavedQueryUsageProbe implements SavedQueryUsageProbe
{
    public function __construct(private WidgetRepository $widgets) {}

    public function countUsages(string $tenantId, string $savedQueryId): int
    {
        return $this->widgets->countUsingSavedQuery($tenantId, $savedQueryId);
    }
}
