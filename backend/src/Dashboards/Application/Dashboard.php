<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use DateTimeImmutable;

/**
 * A personal dashboard as the API returns it.
 *
 * There is no viewer-access field here, and that is the point: a dashboard
 * belongs to exactly one membership and nobody else can reach it. Shared
 * dashboards are a future extension and must not be simulated by handing the
 * row to a second owner.
 */
final readonly class Dashboard
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $ownerMembershipId,
        public string $name,
        public int $position,
        public bool $isDefault,
        public int $widgetCount,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
