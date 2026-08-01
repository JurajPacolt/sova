<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

use DateTimeImmutable;

final readonly class SecurityAuditEventDetails
{
    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function __construct(
        public string $id,
        public AuditActor $actor,
        public ?AuditActor $effectiveUser,
        public ?AuditTenant $tenant,
        public string $eventType,
        public string $outcome,
        public string $reasonCode,
        public string $requestId,
        public ?string $ipAddress,
        public array $metadata,
        public DateTimeImmutable $occurredAt,
    ) {}
}
