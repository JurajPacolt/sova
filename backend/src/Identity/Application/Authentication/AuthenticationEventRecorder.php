<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

interface AuthenticationEventRecorder
{
    public function record(
        string $eventType,
        string $outcome,
        string $reasonCode,
        string $requestId,
        ?string $ipAddress,
        ?string $userId = null,
        ?string $sessionId = null,
    ): void;
}
