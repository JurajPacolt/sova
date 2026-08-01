<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\Template;

use Sova\Dashboards\Domain\WidgetRegistry\WidgetType;

/**
 * One widget of the starter template, with the place it should occupy.
 *
 * The configuration is written in the same shape a client would send, so it
 * goes through the registry's validator like any other — a preset gets no
 * shortcut past the schema of its own type.
 */
final readonly class TemplateWidget
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        public string $queryKey,
        public WidgetType $type,
        public string $title,
        public array $configuration,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}
}
