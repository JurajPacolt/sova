<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use DateTimeImmutable;

final readonly class SessionSummary
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
