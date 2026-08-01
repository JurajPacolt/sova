<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * Bounds how often one member may run a search. It protects the database from a
 * caller who has legitimate access but issues expensive queries in a loop, which
 * is why the bucket is per tenant membership rather than per IP.
 */
interface QueryRateLimiter
{
    /**
     * @return bool false once the caller is over budget; the request must then
     *              be rejected with `QUERY_RATE_LIMITED`
     */
    public function consumeAllowance(string $tenantId, string $userId): bool;
}
