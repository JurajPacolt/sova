<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Sova\Identity\Application\Authentication\LoginRateLimiter;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class DoctrineLoginRateLimiter implements LoginRateLimiter
{
    private string $secret;
    private int $windowSeconds;
    private int $blockSeconds;
    private int $accountAttempts;
    private int $ipAttempts;

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
        $this->windowSeconds = $this->positiveSetting(
            $settings,
            'auth.rate_limit_window_seconds',
        );
        $this->blockSeconds = $this->positiveSetting(
            $settings,
            'auth.rate_limit_block_seconds',
        );
        $this->accountAttempts = $this->positiveSetting(
            $settings,
            'auth.rate_limit_account_attempts',
        );
        $this->ipAttempts = $this->positiveSetting(
            $settings,
            'auth.rate_limit_ip_attempts',
        );
    }

    public function isLimited(string $normalizedEmail, string $ipAddress): bool
    {
        foreach ($this->buckets($normalizedEmail, $ipAddress) as $bucket) {
            $limited = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM authentication_rate_limits
                        WHERE bucket_key = :bucket_key
                            AND blocked_until > CURRENT_TIMESTAMP
                    )
                    SQL,
                ['bucket_key' => $bucket['key']],
            );

            if (in_array($limited, [true, 1, '1', 't'], true)) {
                return true;
            }
        }

        return false;
    }

    public function recordFailure(string $normalizedEmail, string $ipAddress): void
    {
        foreach ($this->buckets($normalizedEmail, $ipAddress) as $bucket) {
            $this->recordBucketFailure(
                $bucket['key'],
                $bucket['type'],
                $bucket['limit'],
            );
        }
    }

    public function resetAccount(string $normalizedEmail): void
    {
        $this->connection->delete('authentication_rate_limits', [
            'bucket_key' => $this->bucketKey('account', $normalizedEmail),
        ]);
    }

    /**
     * @return list<array{key: string, type: string, limit: int}>
     */
    private function buckets(string $normalizedEmail, string $ipAddress): array
    {
        return [
            [
                'key' => $this->bucketKey('account', $normalizedEmail),
                'type' => 'ACCOUNT',
                'limit' => $this->accountAttempts,
            ],
            [
                'key' => $this->bucketKey('ip', $ipAddress),
                'type' => 'IP',
                'limit' => $this->ipAttempts,
            ],
        ];
    }

    private function bucketKey(string $type, string $value): string
    {
        return hash_hmac('sha256', sprintf('%s:%s', $type, $value), $this->secret);
    }

    private function recordBucketFailure(
        string $bucketKey,
        string $bucketType,
        int $limit,
    ): void {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    INSERT INTO authentication_rate_limits (
                        bucket_key,
                        bucket_type,
                        attempt_count,
                        window_started_at,
                        blocked_until,
                        updated_at
                    )
                    VALUES (
                        :bucket_key,
                        :bucket_type,
                        1,
                        CURRENT_TIMESTAMP,
                        CASE
                            WHEN %d <= 1
                                THEN CURRENT_TIMESTAMP + INTERVAL '%d seconds'
                            ELSE NULL
                        END,
                        CURRENT_TIMESTAMP
                    )
                    ON CONFLICT (bucket_key) DO UPDATE SET
                        bucket_type = EXCLUDED.bucket_type,
                        attempt_count = CASE
                            WHEN authentication_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN 1
                            ELSE authentication_rate_limits.attempt_count + 1
                        END,
                        window_started_at = CASE
                            WHEN authentication_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN CURRENT_TIMESTAMP
                            ELSE authentication_rate_limits.window_started_at
                        END,
                        blocked_until = CASE
                            WHEN authentication_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN NULL
                            WHEN authentication_rate_limits.attempt_count + 1 >= %d
                                THEN CURRENT_TIMESTAMP + INTERVAL '%d seconds'
                            ELSE authentication_rate_limits.blocked_until
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    SQL,
                $limit,
                $this->blockSeconds,
                $this->windowSeconds,
                $this->windowSeconds,
                $this->windowSeconds,
                $limit,
                $this->blockSeconds,
            ),
            [
                'bucket_key' => $bucketKey,
                'bucket_type' => $bucketType,
            ],
        );
    }

    private function positiveSetting(Settings $settings, string $key): int
    {
        $value = $settings->int($key);

        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Setting "%s" must be positive.',
                $key,
            ));
        }

        return $value;
    }
}
