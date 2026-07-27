<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use SensitiveParameter;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Application\Security\SessionTokenGenerator;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class LoginService
{
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$'
        . 'RFR4ZnUvVVNqUzRJTFlWLw$RVuAB/pxmfKP67s9oI4QCiVM8PtdeG/jdXlNuZLV+BA';

    private int $sessionTtlSeconds;

    public function __construct(
        private Connection $connection,
        private UserCredentialsRepository $users,
        private UserSessionRepository $sessions,
        private LoginRateLimiter $rateLimiter,
        private AuthenticationEventRecorder $events,
        private PasswordHasher $passwordHasher,
        private SessionTokenGenerator $tokenGenerator,
        Settings $settings,
    ) {
        $this->sessionTtlSeconds = $settings->int(
            'auth.session_ttl_seconds',
            28_800,
        );

        if ($this->sessionTtlSeconds <= 0) {
            throw new InvalidArgumentException(
                'AUTH_SESSION_TTL_SECONDS must be positive.',
            );
        }
    }

    public function login(
        string $normalizedEmail,
        #[SensitiveParameter]
        string $plainTextPassword,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): LoginResult {
        if ($this->rateLimiter->isLimited($normalizedEmail, $ipAddress)) {
            $this->events->record(
                eventType: 'LOGIN',
                outcome: 'RATE_LIMITED',
                reasonCode: 'LOGIN_RATE_LIMITED',
                requestId: $requestId,
                ipAddress: $this->nullableIpAddress($ipAddress),
            );

            throw new DomainProblemException(
                ProblemType::RateLimitExceeded,
                'LOGIN_RATE_LIMITED',
                'Too many login attempts were made. Try again later.',
            );
        }

        $user = $this->users->findByNormalizedEmail($normalizedEmail);
        $passwordHash = $user->passwordHash ?? self::DUMMY_PASSWORD_HASH;
        $passwordMatches = $this->passwordHasher->verify(
            $plainTextPassword,
            $passwordHash,
        );

        if (
            $user === null
            || !$passwordMatches
            || $user->status !== UserStatus::Active
        ) {
            $this->connection->transactional(function () use (
                $normalizedEmail,
                $ipAddress,
                $requestId,
                $user,
            ): void {
                $this->rateLimiter->recordFailure($normalizedEmail, $ipAddress);
                $this->events->record(
                    eventType: 'LOGIN',
                    outcome: 'FAILURE',
                    reasonCode: 'INVALID_CREDENTIALS',
                    requestId: $requestId,
                    ipAddress: $this->nullableIpAddress($ipAddress),
                    userId: $user?->id,
                );
            });

            throw new DomainProblemException(
                ProblemType::AuthenticationRequired,
                'INVALID_CREDENTIALS',
                'The email or password is incorrect.',
            );
        }

        $sessionId = (string) UuidV7::generate();
        $sessionToken = $this->tokenGenerator->generate();
        $csrfToken = $this->tokenGenerator->generate();
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', $this->sessionTtlSeconds));

        $this->connection->transactional(function () use (
            $user,
            $plainTextPassword,
            $passwordHash,
            $sessionId,
            $sessionToken,
            $csrfToken,
            $expiresAt,
            $ipAddress,
            $userAgent,
            $normalizedEmail,
            $requestId,
        ): void {
            if ($this->passwordHasher->needsRehash($passwordHash)) {
                $this->users->updatePasswordHash(
                    $user->id,
                    $this->passwordHasher->hash($plainTextPassword),
                );
            }

            $this->sessions->create(
                sessionId: $sessionId,
                userId: $user->id,
                tokenHash: $sessionToken->hash(),
                csrfTokenHash: $csrfToken->hash(),
                expiresAt: $expiresAt,
                ipAddress: $this->nullableIpAddress($ipAddress),
                userAgent: $userAgent === null
                    ? null
                    : mb_strcut($userAgent, 0, 512, 'UTF-8'),
            );
            $this->rateLimiter->resetAccount($normalizedEmail);
            $this->events->record(
                eventType: 'LOGIN',
                outcome: 'SUCCESS',
                reasonCode: 'LOGIN_SUCCEEDED',
                requestId: $requestId,
                ipAddress: $this->nullableIpAddress($ipAddress),
                userId: $user->id,
                sessionId: $sessionId,
            );
        });

        return new LoginResult(
            user: $user,
            sessionId: $sessionId,
            expiresAt: $expiresAt,
            sessionToken: $sessionToken,
            csrfToken: $csrfToken,
        );
    }

    private function nullableIpAddress(string $ipAddress): ?string
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            ? null
            : $ipAddress;
    }
}
