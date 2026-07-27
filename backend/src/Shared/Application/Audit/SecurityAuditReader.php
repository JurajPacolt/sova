<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

interface SecurityAuditReader
{
    public function page(
        AuditQuery $query,
        ?string $tenantId,
    ): SecurityAuditPage;
}
