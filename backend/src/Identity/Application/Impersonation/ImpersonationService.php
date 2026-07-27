<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class ImpersonationService
{
    private const TTL_SECONDS = 900;

    public function __construct(
        private Connection $connection,
        private ImpersonationRepository $impersonations,
        private UserCredentialsRepository $users,
        private PasswordHasher $passwordHasher,
        private SecurityAuditRecorder $audit,
    ) {}

    public function start(
        SessionContext $session,
        ImpersonationInput $input,
        string $requestId,
        ?string $ipAddress,
    ): ImpersonationDetails {
        if ($session->impersonation !== null) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'IMPERSONATION_ALREADY_ACTIVE',
                'End the current impersonation before starting another one.',
            );
        }

        if ($input->effectiveUserId === $session->actorUserId) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'IMPERSONATION_INPUT_INVALID',
                'The administrator cannot impersonate their own account.',
                [
                    'effective_user_id' => [
                        'Choose a different tenant user.',
                    ],
                ],
            );
        }

        $actor = $this->users->findById($session->actorUserId);

        if (
            $actor === null
            || $actor->status !== UserStatus::Active
            || !$actor->isSuperadmin
            || !$this->passwordHasher->verify(
                $input->password,
                $actor->passwordHash,
            )
        ) {
            throw new DomainProblemException(
                ProblemType::AuthenticationRequired,
                'IMPERSONATION_REAUTHENTICATION_FAILED',
                'The administrator password could not be verified.',
            );
        }

        if ($this->passwordHasher->needsRehash($actor->passwordHash)) {
            $this->users->updatePasswordHash(
                $actor->id,
                $this->passwordHasher->hash($input->password),
            );
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify(sprintf('+%d seconds', self::TTL_SECONDS));

        return $this->connection->transactional(function () use (
            $session,
            $input,
            $requestId,
            $ipAddress,
            $now,
            $expiresAt,
        ): ImpersonationDetails {
            if (!$this->impersonations->lockActiveSession(
                $session->sessionId,
                $session->actorUserId,
            )) {
                throw new DomainProblemException(
                    ProblemType::AuthenticationRequired,
                    'SESSION_REQUIRED',
                    'A valid session is required to start impersonation.',
                );
            }

            $this->impersonations->closeExpiredForSession(
                $session->sessionId,
                $now,
            );

            if ($this->impersonations->hasOpenForSession(
                $session->sessionId,
            )) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'IMPERSONATION_ALREADY_ACTIVE',
                    'End the current impersonation before starting another one.',
                );
            }

            $target = $this->impersonations->findEligibleTarget(
                $input->tenantId,
                $input->effectiveUserId,
            );

            if ($target === null) {
                throw new DomainProblemException(
                    ProblemType::ResourceNotFound,
                    'IMPERSONATION_TARGET_NOT_FOUND',
                    'The active tenant user was not found.',
                );
            }

            $details = $this->impersonations->create(
                id: (string) UuidV7::generate(),
                sessionId: $session->sessionId,
                actorUserId: $session->actorUserId,
                effectiveUserId: $target->userId,
                tenantId: $target->tenantId,
                reason: $input->reason,
                reauthenticatedAt: $now,
                startedAt: $now,
                expiresAt: $expiresAt,
            );
            $this->audit->record(
                eventType: 'IMPERSONATION_STARTED',
                outcome: 'SUCCESS',
                reasonCode: 'IMPERSONATION_STARTED',
                requestId: $requestId,
                actorUserId: $session->actorUserId,
                tenantId: $target->tenantId,
                effectiveUserId: $target->userId,
                ipAddress: $ipAddress,
                metadata: [
                    'impersonation_id' => $details->id,
                    'reason' => $input->reason,
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                ],
            );

            return $details;
        });
    }

    public function endCurrent(
        SessionContext $session,
        string $requestId,
        ?string $ipAddress,
    ): void {
        if ($session->impersonation === null) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'IMPERSONATION_NOT_ACTIVE',
                'There is no current impersonation to end.',
            );
        }

        $this->end(
            $session,
            'USER_ENDED',
            $requestId,
            $ipAddress,
            true,
        );
    }

    public function endForSessionClosure(
        SessionContext $session,
        string $endReason,
        string $requestId,
        ?string $ipAddress,
    ): void {
        if ($session->impersonation === null) {
            return;
        }

        $this->end(
            $session,
            $endReason,
            $requestId,
            $ipAddress,
            false,
        );
    }

    private function end(
        SessionContext $session,
        string $endReason,
        string $requestId,
        ?string $ipAddress,
        bool $mustExist,
    ): void {
        $context = $session->impersonation;

        if ($context === null) {
            return;
        }

        $ended = $this->connection->transactional(function () use (
            $session,
            $context,
            $endReason,
            $requestId,
            $ipAddress,
        ): bool {
            if (!$this->impersonations->lockActiveSession(
                $session->sessionId,
                $session->actorUserId,
            )) {
                return false;
            }

            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $ended = $this->impersonations->end(
                $context->id,
                $session->sessionId,
                $endedAt,
                $endReason,
            );

            if ($ended) {
                $this->audit->record(
                    eventType: 'IMPERSONATION_ENDED',
                    outcome: 'SUCCESS',
                    reasonCode: $endReason,
                    requestId: $requestId,
                    actorUserId: $session->actorUserId,
                    tenantId: $context->tenantId,
                    effectiveUserId: $session->userId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'impersonation_id' => $context->id,
                        'status_before_end' => $context->status->value,
                    ],
                );
            }

            return $ended;
        });

        if (!$ended && $mustExist) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'IMPERSONATION_NOT_ACTIVE',
                'There is no current impersonation to end.',
            );
        }
    }
}
