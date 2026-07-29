<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use RuntimeException;

/**
 * Raised when PostgreSQL aborts a search because it exceeded the configured
 * statement timeout. It is a safety limit, not a bug, so it is reported to the
 * caller as `QUERY_TIMEOUT` rather than as a server error.
 */
final class QueryTimedOutException extends RuntimeException {}
