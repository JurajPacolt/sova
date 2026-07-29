<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Issues\Application\Search\QueryRateLimiter;
use Sova\Shared\Infrastructure\Configuration\Settings;

/**
 * Fixed-window counter per tenant and user, following the same shape as the
 * authentication limiters: the bucket key is an HMAC, so the table never stores
 * a raw identifier, and the whole check is one atomic upsert.
 */
final readonly class DoctrineQueryRateLimiter implements QueryRateLimiter
{
    private string $secret;
    private int $windowSeconds;
    private int $requests;

    public function __construct(
        private Connection $connection,
        Settings $settings,
    ) {
        $secret = $settings->string('auth.rate_limit_secret', '');

        if (strlen($secret) < 16) {
            throw new RuntimeException(
                'AUTH_RATE_LIMIT_SECRET must contain at least 16 characters.',
            );
        }

        $this->secret = $secret;
        $this->windowSeconds = max(1, $settings->int('search.rate_limit_window_seconds', 60));
        $this->requests = max(1, $settings->int('search.rate_limit_requests', 120));
    }

    public function consumeAllowance(string $tenantId, string $userId): bool
    {
        $allowed = $this->connection->fetchOne(
            sprintf(
                <<<'SQL'
                    INSERT INTO issue_query_rate_limits (
                        bucket_key,
                        request_count,
                        window_started_at,
                        updated_at
                    )
                    VALUES (:bucket_key, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT (bucket_key) DO UPDATE SET
                        request_count = CASE
                            WHEN issue_query_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN 1
                            ELSE issue_query_rate_limits.request_count + 1
                        END,
                        window_started_at = CASE
                            WHEN issue_query_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN CURRENT_TIMESTAMP
                            ELSE issue_query_rate_limits.window_started_at
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    RETURNING request_count <= %d
                    SQL,
                $this->windowSeconds,
                $this->windowSeconds,
                $this->requests,
            ),
            ['bucket_key' => $this->bucketKey($tenantId, $userId)],
        );

        return in_array($allowed, [true, 1, '1', 't'], true);
    }

    private function bucketKey(string $tenantId, string $userId): string
    {
        return hash_hmac(
            'sha256',
            sprintf('issue-query:%s:%s', $tenantId, $userId),
            $this->secret,
        );
    }
}
