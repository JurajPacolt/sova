<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

final readonly class AuditActor
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
    ) {}
}
