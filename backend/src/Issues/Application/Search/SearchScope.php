<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * The authorised boundary every SovaQL execution runs inside. It is derived on
 * the server from the route tenant and the effective user's `issue.view` grants
 * — never from anything the client sent — and it is the only thing that decides
 * which rows may be touched. A reference the caller may not reach is resolved
 * against this scope too, so an inaccessible project code is indistinguishable
 * from a non-existent one.
 */
final readonly class SearchScope
{
    /**
     * @param list<string> $projectIds projects where the effective user holds
     *                                 `issue.view`, already filtered to active
     *                                 projects of this tenant
     */
    public function __construct(
        public string $tenantId,
        public string $effectiveUserId,
        public array $projectIds,
        public int $authorizationRevision,
    ) {}

    /**
     * With no reachable project there is nothing to search; the caller must
     * short-circuit instead of running an unbounded query.
     */
    public function isEmpty(): bool
    {
        return $this->projectIds === [];
    }
}
