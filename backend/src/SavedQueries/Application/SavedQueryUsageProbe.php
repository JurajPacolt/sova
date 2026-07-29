<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Application;

/**
 * How many things still depend on a saved query.
 *
 * The port lives here and the implementation lives in the module that does the
 * depending, so the arrow points **towards** saved queries: dashboards know
 * about queries, queries know nothing about dashboards. Without the inversion
 * this module would have to import the one built on top of it.
 */
interface SavedQueryUsageProbe
{
    public function countUsages(string $tenantId, string $savedQueryId): int;
}
