<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\WidgetRegistry;

/**
 * What one widget type is: its stable key, the version of its configuration
 * schema, the sizes it may take on the 12-column grid, and the dimensions it
 * can aggregate by.
 *
 * Labels are localisation **keys**, not text. The backend never ships a
 * user-facing string here: the catalogs own the wording, and shipping English
 * from the server would put one language outside the typed contract.
 */
final readonly class WidgetDefinition
{
    /**
     * @param list<WidgetDimension> $dimensions
     */
    public function __construct(
        public WidgetType $type,
        public int $schemaVersion,
        public string $labelKey,
        public string $descriptionKey,
        public int $minWidth,
        public int $minHeight,
        public int $defaultWidth,
        public int $defaultHeight,
        public int $maxWidth,
        public int $maxHeight,
        public array $dimensions,
    ) {}
}
