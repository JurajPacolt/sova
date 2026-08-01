<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Access;

use Sova\Tenancy\Domain\Tenant\TenantStatus;

final readonly class AccessibleTenant
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public TenantStatus $status,
        public ?string $membershipId,
        public bool $viaSuperadmin,
    ) {}
}
