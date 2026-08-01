<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Sova\Identity\Application\Security\PublicEmailRateLimiter;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class DoctrinePublicEmailRateLimiter implements PublicEmailRateLimiter
{
    private string $secret;
    private int $windowSeconds;
    private int $blockSeconds;
    private int $accountRequests;
    private int $ipRequests;

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
            'auth.recovery_rate_limit_window_seconds',
        );
        $this->blockSeconds = $this->positiveSetting(
            $settings,
            'auth.recovery_rate_limit_block_seconds',
        );
        $this->accountRequests = $this->positiveSetting(
            $settings,
            'auth.recovery_rate_limit_account_requests',
        );
        $this->ipRequests = $this->positiveSetting(
            $settings,
            'auth.recovery_rate_limit_ip_requests',
        );
    }

    public function consumeAllowance(
        OneTimeTokenPurpose $purpose,
        string $normalizedEmail,
        string $ipAddress,
    ): bool {
        $allowed = true;

        foreach ($this->buckets($purpose, $normalizedEmail, $ipAddress) as $bucket) {
            if (!$this->consumeBucket(
                $bucket['key'],
                $bucket['type'],
                $bucket['limit'],
            )) {
                $allowed = false;
            }
        }

        return $allowed;
    }

    /**
     * @return list<array{key: string, type: string, limit: int}>
     */
    private function buckets(
        OneTimeTokenPurpose $purpose,
        string $normalizedEmail,
        string $ipAddress,
    ): array {
        $scope = strtolower(str_replace('_', '-', $purpose->value));

        return [
            [
                'key' => $this->bucketKey(
                    sprintf('%s-account', $scope),
                    $normalizedEmail,
                ),
                'type' => 'ACCOUNT',
                'limit' => $this->accountRequests,
            ],
            [
                'key' => $this->bucketKey(
                    sprintf('%s-ip', $scope),
                    $ipAddress,
                ),
                'type' => 'IP',
                'limit' => $this->ipRequests,
            ],
        ];
    }

    private function bucketKey(string $type, string $value): string
    {
        return hash_hmac('sha256', sprintf('%s:%s', $type, $value), $this->secret);
    }

    private function consumeBucket(
        string $bucketKey,
        string $bucketType,
        int $limit,
    ): bool {
        $allowed = $this->connection->fetchOne(
            sprintf(
                <<<'SQL'
                    INSERT INTO authentication_recovery_rate_limits (
                        bucket_key,
                        bucket_type,
                        request_count,
                        window_started_at,
                        blocked_until,
                        updated_at
                    )
                    VALUES (
                        :bucket_key,
                        :bucket_type,
                        1,
                        CURRENT_TIMESTAMP,
                        NULL,
                        CURRENT_TIMESTAMP
                    )
                    ON CONFLICT (bucket_key) DO UPDATE SET
                        bucket_type = EXCLUDED.bucket_type,
                        request_count = CASE
                            WHEN authentication_recovery_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN 1
                            ELSE authentication_recovery_rate_limits.request_count + 1
                        END,
                        window_started_at = CASE
                            WHEN authentication_recovery_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN CURRENT_TIMESTAMP
                            ELSE authentication_recovery_rate_limits.window_started_at
                        END,
                        blocked_until = CASE
                            WHEN authentication_recovery_rate_limits.window_started_at
                                <= CURRENT_TIMESTAMP - INTERVAL '%d seconds'
                                THEN NULL
                            WHEN authentication_recovery_rate_limits.blocked_until
                                > CURRENT_TIMESTAMP
                                THEN authentication_recovery_rate_limits.blocked_until
                            WHEN authentication_recovery_rate_limits.request_count + 1 > %d
                                THEN CURRENT_TIMESTAMP + INTERVAL '%d seconds'
                            ELSE NULL
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    RETURNING blocked_until IS NULL
                    SQL,
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

        return in_array($allowed, [true, 1, '1', 't'], true);
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
