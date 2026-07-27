<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

final readonly class AuditTenant
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {}
}
