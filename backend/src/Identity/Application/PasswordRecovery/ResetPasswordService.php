<?php

declare(strict_types=1);

namespace Sova\Identity\Application\PasswordRecovery;

use Doctrine\DBAL\Connection;
use SensitiveParameter;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Identity\Application\Authentication\LoginRateLimiter;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Application\Security\PasswordPolicy;
use Sova\Identity\Application\Token\OneTimeTokenRepository;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class ResetPasswordService
{
    public function __construct(
        private Connection $connection,
        private OneTimeTokenGenerator $tokenGenerator,
        private OneTimeTokenRepository $tokens,
        private UserCredentialsRepository $users,
        private UserSessionRepository $sessions,
        private PasswordPolicy $passwordPolicy,
        private PasswordHasher $passwordHasher,
        private LoginRateLimiter $loginRateLimiter,
        private AuthenticationEventRecorder $events,
    ) {}

    public function reset(
        #[SensitiveParameter]
        string $plainTextToken,
        #[SensitiveParameter]
        string $newPassword,
        string $ipAddress,
        string $requestId,
    ): void {
        if (!$this->tokenGenerator->hasValidFormat($plainTextToken)) {
            throw $this->invalidToken();
        }

        $tokenHash = $this->tokenGenerator->hash($plainTextToken);

        $this->connection->transactional(function () use (
            $tokenHash,
            $newPassword,
            $ipAddress,
            $requestId,
        ): void {
            $consumed = $this->tokens->consumeActive(
                $tokenHash,
                OneTimeTokenPurpose::PasswordReset,
            );

            if ($consumed === null) {
                throw $this->invalidToken();
            }

            $user = $this->users->findById($consumed->userId);

            if ($user === null || $user->status !== UserStatus::Active) {
                throw $this->invalidToken();
            }

            $this->passwordPolicy->assertAcceptable($newPassword, $user);
            $this->users->updatePasswordHash(
                $user->id,
                $this->passwordHasher->hash($newPassword),
            );
            $this->sessions->revokeAllForUser(
                $user->id,
                'PASSWORD_RESET',
            );
            $this->loginRateLimiter->resetAccount(strtolower($user->email));
            $this->events->record(
                eventType: 'PASSWORD_RESET_COMPLETED',
                outcome: 'SUCCESS',
                reasonCode: 'PASSWORD_RESET_SUCCEEDED',
                requestId: $requestId,
                ipAddress: $this->nullableIpAddress($ipAddress),
                userId: $user->id,
            );
        });
    }

    private function invalidToken(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Gone,
            'PASSWORD_RESET_TOKEN_INVALID',
            'The password reset link is invalid or has expired.',
        );
    }

    private function nullableIpAddress(string $ipAddress): ?string
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            ? null
            : $ipAddress;
    }
}
