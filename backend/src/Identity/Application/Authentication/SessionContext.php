<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use Sova\Identity\Application\Impersonation\ImpersonationDetails;

final readonly class SessionContext
{
    public function __construct(
        public string $sessionId,
        public string $actorUserId,
        public string $actorEmail,
        public string $actorDisplayName,
        public bool $actorIsSuperadmin,
        public bool $actorHasSuperadminRole,
        public string $userId,
        public string $email,
        public string $displayName,
        public string $preferredLocale,
        public string $csrfTokenHash,
        public bool $isSuperadmin,
        public bool $mfaEnabled,
        public bool $mfaVerified,
        public bool $mfaEnrollmentRequired,
        public int $mfaRecoveryCodesRemaining,
        public ?ImpersonationDetails $impersonation,
    ) {}

    public function effectiveUserIdForAudit(): ?string
    {
        return $this->impersonation === null ? null : $this->userId;
    }
}
