<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

use DateTimeImmutable;

final readonly class AuditQuery
{
    public function __construct(
        public int $limit,
        public ?AuditCursor $cursor,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public ?string $actorUserId,
        public ?string $eventType,
        public ?string $outcome,
        public ?string $requestId,
    ) {}
}
