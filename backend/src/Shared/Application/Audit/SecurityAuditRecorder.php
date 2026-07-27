<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

interface SecurityAuditRecorder
{
    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function record(
        string $eventType,
        string $outcome,
        string $reasonCode,
        string $requestId,
        string $actorUserId,
        ?string $tenantId = null,
        ?string $effectiveUserId = null,
        ?string $ipAddress = null,
        array $metadata = [],
    ): void;
}
