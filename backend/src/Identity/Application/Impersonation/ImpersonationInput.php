<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

use SensitiveParameter;

final readonly class ImpersonationInput
{
    public function __construct(
        public string $tenantId,
        public string $effectiveUserId,
        public string $reason,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
