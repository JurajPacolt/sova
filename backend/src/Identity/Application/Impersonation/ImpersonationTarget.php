<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

final readonly class ImpersonationTarget
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $displayName,
        public string $preferredLocale,
        public string $tenantId,
        public string $tenantName,
        public string $tenantSlug,
    ) {}
}
