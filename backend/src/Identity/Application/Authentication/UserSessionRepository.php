<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use DateTimeImmutable;

interface UserSessionRepository
{
    public function create(
        string $sessionId,
        string $userId,
        string $tokenHash,
        string $csrfTokenHash,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
        ?DateTimeImmutable $mfaVerifiedAt,
    ): void;

    public function findActiveByTokenHash(string $tokenHash): ?SessionContext;

    /**
     * @return list<SessionSummary>
     */
    public function listActiveForUser(string $userId): array;

    public function touch(string $sessionId): void;

    public function revoke(
        string $sessionId,
        string $userId,
        string $reason,
    ): bool;

    public function revokeAllForUser(string $userId, string $reason): int;

    public function markMfaVerified(
        string $sessionId,
        string $userId,
        DateTimeImmutable $verifiedAt,
    ): bool;

    public function clearMfaVerificationForUser(string $userId): void;

    public function revokeOtherForUser(
        string $userId,
        string $currentSessionId,
        string $reason,
    ): int;
}
