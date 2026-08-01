<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

use DateTimeImmutable;
use Sova\Workgroups\Domain\WorkgroupStatus;

final readonly class WorkgroupDetails
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $description,
        public WorkgroupStatus $status,
        public int $memberCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
