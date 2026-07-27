<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

use DateTimeImmutable;

final readonly class ImpersonationDetails
{
    public function __construct(
        public string $id,
        public string $sessionId,
        public string $actorUserId,
        public string $actorEmail,
        public string $actorDisplayName,
        public string $effectiveUserId,
        public string $effectiveUserEmail,
        public string $effectiveUserDisplayName,
        public string $effectiveUserPreferredLocale,
        public string $tenantId,
        public string $tenantName,
        public string $tenantSlug,
        public string $reason,
        public DateTimeImmutable $reauthenticatedAt,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $expiresAt,
        public ImpersonationStatus $status,
    ) {}
}
