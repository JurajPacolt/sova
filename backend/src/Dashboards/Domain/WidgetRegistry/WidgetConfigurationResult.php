<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\WidgetRegistry;

/**
 * The outcome of checking a widget's configuration against its type.
 *
 * The validator returns this rather than throwing: the domain layer stays free
 * of HTTP problem types, and the application layer decides what a rejection
 * looks like on the wire.
 */
final readonly class WidgetConfigurationResult
{
    /**
     * @param array<string, mixed>        $configuration the normalised form, defaults filled in
     * @param array<string, list<string>> $errors        keyed by configuration field
     */
    private function __construct(
        public bool $valid,
        public array $configuration,
        public array $errors,
    ) {}

    /**
     * @param array<string, mixed> $configuration
     */
    public static function accepted(array $configuration): self
    {
        return new self(true, $configuration, []);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function rejected(array $errors): self
    {
        return new self(false, [], $errors);
    }
}
