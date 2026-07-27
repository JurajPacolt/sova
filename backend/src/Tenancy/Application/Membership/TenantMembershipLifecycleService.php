<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Membership;

use Doctrine\DBAL\Connection;
use Sova\Authorization\Application\TenantOwnershipGuard;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Tenancy\Domain\Membership\MembershipStatus;
use ValueError;

final readonly class TenantMembershipLifecycleService
{
    public function __construct(
        private Connection $connection,
        private TenantMembershipRepository $memberships,
        private TenantOwnershipGuard $ownership,
        private SecurityAuditRecorder $audit,
    ) {}

    public function changeStatus(
        string $tenantId,
        string $membershipId,
        MembershipStatus $targetStatus,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        bool $mayManageOwners,
        ?string $effectiveUserId = null,
    ): TenantMembershipDetails {
        return $this->connection->transactional(function () use (
            $tenantId,
            $membershipId,
            $targetStatus,
            $actorUserId,
            $requestId,
            $ipAddress,
            $mayManageOwners,
            $effectiveUserId,
        ): TenantMembershipDetails {
            if (!$this->memberships->lockActiveTenant($tenantId)) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_MEMBERSHIP_OPERATION_UNAVAILABLE',
                    'Tenant memberships cannot be changed in the current tenant state.',
                );
            }

            $existing = $this->memberships->findForTenant(
                $tenantId,
                $membershipId,
                true,
            );

            if ($existing === null) {
                throw $this->notFound();
            }

            $currentStatus = $this->status($existing->status);

            if ($currentStatus === $targetStatus) {
                return $existing;
            }

            if ($existing->userId === ($effectiveUserId ?? $actorUserId)) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_MEMBERSHIP_SELF_MANAGEMENT_FORBIDDEN',
                    'Use a different administrator to change your own tenant membership.',
                );
            }

            if (!$currentStatus->canTransitionTo($targetStatus)) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_MEMBERSHIP_TRANSITION_INVALID',
                    'The tenant membership status transition is not allowed.',
                );
            }

            $this->ownership->assertCanChangeMembershipStatus(
                tenantId: $tenantId,
                membershipId: $membershipId,
                currentStatus: $currentStatus->value,
                targetStatus: $targetStatus->value,
                mayManageOwners: $mayManageOwners,
            );
            $this->memberships->changeStatus(
                $tenantId,
                $membershipId,
                $targetStatus->value,
            );
            $updated = $this->memberships->findForTenant(
                $tenantId,
                $membershipId,
            );

            if ($updated === null) {
                throw $this->notFound();
            }

            $this->audit->record(
                eventType: $this->eventType($targetStatus),
                outcome: 'SUCCESS',
                reasonCode: $this->reasonCode($targetStatus),
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'membership_id' => $membershipId,
                    'target_user_id' => $existing->userId,
                    'previous_status' => $currentStatus->value,
                    'status' => $targetStatus->value,
                ],
            );

            return $updated;
        });
    }

    private function status(string $value): MembershipStatus
    {
        try {
            return MembershipStatus::from($value);
        } catch (ValueError $exception) {
            throw new \RuntimeException(
                sprintf('Unknown tenant membership status "%s".', $value),
                previous: $exception,
            );
        }
    }

    private function eventType(MembershipStatus $status): string
    {
        return match ($status) {
            MembershipStatus::Active => 'TENANT_MEMBERSHIP_REACTIVATED',
            MembershipStatus::Disabled => 'TENANT_MEMBERSHIP_DISABLED',
            MembershipStatus::Removed => 'TENANT_MEMBERSHIP_REMOVED',
        };
    }

    private function reasonCode(MembershipStatus $status): string
    {
        return match ($status) {
            MembershipStatus::Active => 'MEMBERSHIP_REACTIVATED',
            MembershipStatus::Disabled => 'MEMBERSHIP_DISABLED',
            MembershipStatus::Removed => 'MEMBERSHIP_REMOVED',
        };
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_MEMBERSHIP_NOT_FOUND',
            'The tenant membership was not found.',
        );
    }
}
