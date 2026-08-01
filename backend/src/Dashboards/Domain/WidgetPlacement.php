<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain;

/**
 * Where one widget sits on the 12-column grid.
 *
 * `y` is unbounded on purpose — a dashboard scrolls downwards, so only the
 * horizontal extent is fixed.
 */
final readonly class WidgetPlacement
{
    public function __construct(
        public string $widgetId,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    public function overlaps(self $other): bool
    {
        return $this->x < $other->x + $other->width
            && $other->x < $this->x + $this->width
            && $this->y < $other->y + $other->height
            && $other->y < $this->y + $this->height;
    }
}
