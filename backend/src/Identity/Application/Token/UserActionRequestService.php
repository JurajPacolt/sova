<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Token;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Identity\Application\Security\PublicEmailRateLimiter;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class UserActionRequestService
{
    private int $requestTtlSeconds;

    public function __construct(
        private Connection $connection,
        private PublicEmailRateLimiter $rateLimiter,
        private UserActionRequestPublisher $publisher,
        private AuthenticationEventRecorder $events,
        Settings $settings,
    ) {
        $this->requestTtlSeconds = $settings->int(
            'auth.recovery_request_ttl_seconds',
            900,
        );

        if ($this->requestTtlSeconds <= 0) {
            throw new InvalidArgumentException(
                'AUTH_RECOVERY_REQUEST_TTL_SECONDS must be positive.',
            );
        }
    }

    public function request(
        OneTimeTokenPurpose $purpose,
        string $normalizedEmail,
        string $ipAddress,
        string $requestId,
    ): void {
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', $this->requestTtlSeconds));

        $this->connection->transactional(function () use (
            $purpose,
            $normalizedEmail,
            $ipAddress,
            $requestId,
            $expiresAt,
        ): void {
            $allowed = $this->rateLimiter->consumeAllowance(
                $purpose,
                $normalizedEmail,
                $ipAddress,
            );

            if ($allowed) {
                $this->publisher->publish(
                    $purpose,
                    $normalizedEmail,
                    $expiresAt,
                );
            }

            $eventPrefix = match ($purpose) {
                OneTimeTokenPurpose::PasswordReset => 'PASSWORD_RESET',
                OneTimeTokenPurpose::EmailVerification => 'EMAIL_VERIFICATION',
            };
            $this->events->record(
                eventType: sprintf('%s_REQUESTED', $eventPrefix),
                outcome: $allowed ? 'SUCCESS' : 'RATE_LIMITED',
                reasonCode: $allowed
                    ? sprintf('%s_REQUEST_ACCEPTED', $eventPrefix)
                    : sprintf('%s_REQUEST_SUPPRESSED', $eventPrefix),
                requestId: $requestId,
                ipAddress: $this->nullableIpAddress($ipAddress),
            );
        });
    }

    private function nullableIpAddress(string $ipAddress): ?string
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            ? null
            : $ipAddress;
    }
}
