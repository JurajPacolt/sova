<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

use DateTimeImmutable;

interface ImpersonationRepository
{
    public function lockActiveSession(
        string $sessionId,
        string $actorUserId,
    ): bool;

    public function closeExpiredForSession(
        string $sessionId,
        DateTimeImmutable $endedAt,
    ): void;

    public function hasOpenForSession(string $sessionId): bool;

    public function findEligibleTarget(
        string $tenantId,
        string $effectiveUserId,
    ): ?ImpersonationTarget;

    public function create(
        string $id,
        string $sessionId,
        string $actorUserId,
        string $effectiveUserId,
        string $tenantId,
        string $reason,
        DateTimeImmutable $reauthenticatedAt,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $expiresAt,
    ): ImpersonationDetails;

    public function end(
        string $id,
        string $sessionId,
        DateTimeImmutable $endedAt,
        string $endReason,
    ): bool;

    public function findOpenForSession(
        string $sessionId,
    ): ?ImpersonationDetails;
}
