<?php

declare(strict_types=1);

namespace Sova\Identity\Application\EmailVerification;

use Doctrine\DBAL\Connection;
use SensitiveParameter;
use Sova\Identity\Application\Authentication\AuthenticationEventRecorder;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Application\Token\OneTimeTokenRepository;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class VerifyEmailService
{
    public function __construct(
        private Connection $connection,
        private OneTimeTokenGenerator $tokenGenerator,
        private OneTimeTokenRepository $tokens,
        private UserCredentialsRepository $users,
        private AuthenticationEventRecorder $events,
    ) {}

    public function verify(
        #[SensitiveParameter]
        string $plainTextToken,
        string $ipAddress,
        string $requestId,
    ): EmailVerificationOutcome {
        if (!$this->tokenGenerator->hasValidFormat($plainTextToken)) {
            throw $this->invalidToken();
        }

        $tokenHash = $this->tokenGenerator->hash($plainTextToken);

        return $this->connection->transactional(function () use (
            $tokenHash,
            $ipAddress,
            $requestId,
        ): EmailVerificationOutcome {
            $consumed = $this->tokens->consumeActive(
                $tokenHash,
                OneTimeTokenPurpose::EmailVerification,
            );

            if ($consumed === null) {
                $previouslyConsumed = $this->tokens->findConsumed(
                    $tokenHash,
                    OneTimeTokenPurpose::EmailVerification,
                );

                if ($previouslyConsumed === null) {
                    throw $this->invalidToken();
                }

                $user = $this->users->findById($previouslyConsumed->userId);

                if (!$this->isVerified($user)) {
                    throw $this->invalidToken();
                }

                return EmailVerificationOutcome::AlreadyVerified;
            }

            $user = $this->users->findById($consumed->userId);

            if ($this->isVerified($user)) {
                return EmailVerificationOutcome::AlreadyVerified;
            }

            if (
                $user === null
                || $user->status !== UserStatus::PendingVerification
                || !$this->users->markEmailVerified($user->id)
            ) {
                throw $this->invalidToken();
            }

            $this->events->record(
                eventType: 'EMAIL_VERIFIED',
                outcome: 'SUCCESS',
                reasonCode: 'EMAIL_VERIFICATION_SUCCEEDED',
                requestId: $requestId,
                ipAddress: $this->nullableIpAddress($ipAddress),
                userId: $user->id,
            );

            return EmailVerificationOutcome::Verified;
        });
    }

    private function isVerified(?UserCredentials $user): bool
    {
        return $user !== null
            && $user->status === UserStatus::Active
            && $user->emailVerifiedAt !== null;
    }

    private function invalidToken(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Gone,
            'EMAIL_VERIFICATION_TOKEN_INVALID',
            'The email verification link is invalid or has expired.',
        );
    }

    private function nullableIpAddress(string $ipAddress): ?string
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            ? null
            : $ipAddress;
    }
}
