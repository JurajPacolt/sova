<?php

declare(strict_types=1);

namespace Sova\Identity\Application\System;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class SystemUserAdministrationService
{
    public function __construct(
        private Connection $connection,
        private SystemUserRepository $users,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @return list<SystemUserDetails>
     */
    public function list(): array
    {
        return $this->users->listAll();
    }

    public function changeStatus(
        string $userId,
        UserStatus $targetStatus,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): SystemUserDetails {
        return $this->connection->transactional(
            function () use (
                $userId,
                $targetStatus,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): SystemUserDetails {
                $user = $this->users->findById($userId, true);

                if ($user === null) {
                    throw $this->userNotFound();
                }

                if ($user->status === $targetStatus) {
                    return $user;
                }

                if ($userId === $actorUserId) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'SYSTEM_USER_SELF_MANAGEMENT_FORBIDDEN',
                        'Use a different administrator to change your own account status.',
                    );
                }

                if (!$user->status->canTransitionTo($targetStatus)) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'SYSTEM_USER_STATUS_TRANSITION_INVALID',
                        'The requested user status transition is not allowed.',
                    );
                }

                if (
                    $user->isSuperadmin
                    && $targetStatus !== UserStatus::Active
                ) {
                    $this->assertAnotherActiveSuperadminExists();
                }

                $this->users->changeStatus($userId, $targetStatus);
                $this->audit->record(
                    eventType: 'SYSTEM_USER_STATUS_CHANGED',
                    outcome: 'SUCCESS',
                    reasonCode: 'SYSTEM_USER_STATUS_CHANGED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'target_user_id' => $userId,
                        'previous_status' => $user->status->value,
                        'status' => $targetStatus->value,
                    ],
                );

                return $this->reload($userId);
            },
        );
    }

    public function grantSuperadmin(
        string $userId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): SystemUserDetails {
        return $this->connection->transactional(
            function () use (
                $userId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): SystemUserDetails {
                $user = $this->users->findById($userId, true);

                if ($user === null) {
                    throw $this->userNotFound();
                }

                if ($user->isSuperadmin) {
                    return $user;
                }

                $this->users->grantSuperadmin($userId, $actorUserId);
                $this->audit->record(
                    eventType: 'SYSTEM_SUPERADMIN_GRANTED',
                    outcome: 'SUCCESS',
                    reasonCode: 'SYSTEM_SUPERADMIN_GRANTED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    ipAddress: $ipAddress,
                    metadata: ['target_user_id' => $userId],
                );

                return $this->reload($userId);
            },
        );
    }

    public function revokeSuperadmin(
        string $userId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): SystemUserDetails {
        return $this->connection->transactional(
            function () use (
                $userId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): SystemUserDetails {
                $user = $this->users->findById($userId, true);

                if ($user === null) {
                    throw $this->userNotFound();
                }

                if (!$user->isSuperadmin) {
                    return $user;
                }

                if ($userId === $actorUserId) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'SYSTEM_SUPERADMIN_SELF_MANAGEMENT_FORBIDDEN',
                        'Use a different administrator to revoke your own superadmin role.',
                    );
                }

                $this->assertAnotherActiveSuperadminExists();
                $this->users->revokeSuperadmin($userId);
                $this->audit->record(
                    eventType: 'SYSTEM_SUPERADMIN_REVOKED',
                    outcome: 'SUCCESS',
                    reasonCode: 'SYSTEM_SUPERADMIN_REVOKED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    ipAddress: $ipAddress,
                    metadata: ['target_user_id' => $userId],
                );

                return $this->reload($userId);
            },
        );
    }

    private function assertAnotherActiveSuperadminExists(): void
    {
        if ($this->users->activeSuperadminCount(true) > 1) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::Conflict,
            'SYSTEM_LAST_SUPERADMIN_REQUIRED',
            'The system must retain at least one active superadmin.',
        );
    }

    private function reload(string $userId): SystemUserDetails
    {
        $updated = $this->users->findById($userId);

        if ($updated === null) {
            throw new RuntimeException(
                'The updated system user could not be loaded.',
            );
        }

        return $updated;
    }

    private function userNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'SYSTEM_USER_NOT_FOUND',
            'The system user was not found.',
        );
    }
}
