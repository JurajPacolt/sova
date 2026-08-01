<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * Executes a compiled query. Implementations must prepend the tenant and project
 * predicate themselves — the compiled filter is never trusted to carry it — and
 * must run under a statement timeout.
 */
interface IssueSearchRepository
{
    /**
     * @return list<SearchRow>
     *
     * @throws QueryTimedOutException when the database aborts the statement
     */
    public function search(
        SearchScope $scope,
        CompiledQuery $compiled,
        ?SearchCursor $cursor,
        int $limit,
    ): array;
}
