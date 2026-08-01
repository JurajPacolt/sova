<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use DateTimeImmutable;

/**
 * One widget instance as the API returns it.
 *
 * {@see $available} is false when the stored `type_key` is not in this
 * deployment's registry. The row is still returned — hiding it would make the
 * widget impossible to remove — but the server does not guess what an unknown
 * configuration meant, and the client shows "widget unavailable" (spec §8.3).
 *
 * {@see $sourceName} is the saved query's name, carried so a dashboard renders
 * its sources without a second round trip. It says nothing about the issues the
 * query returns; those are still intersected with the caller's own scope every
 * time the widget loads.
 */
final readonly class DashboardWidget
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        public string $id,
        public string $dashboardId,
        public string $savedQueryId,
        public ?string $sourceName,
        public bool $sourceReachable,
        public string $typeKey,
        public bool $available,
        public int $schemaVersion,
        public string $title,
        public array $configuration,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
