<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Workgroups\Domain\WorkgroupMemberRole;
use Sova\Workgroups\Domain\WorkgroupStatus;

final readonly class WorkgroupAdministrationService
{
    public function __construct(
        private Connection $connection,
        private WorkgroupRepository $workgroups,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @return list<WorkgroupDetails>
     */
    public function list(string $tenantId): array
    {
        return $this->workgroups->listForTenant($tenantId);
    }

    public function create(
        string $tenantId,
        CreateWorkgroupInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): WorkgroupDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $input,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): WorkgroupDetails {
                $workgroupId = (string) UuidV7::generate();
                $this->workgroups->create(
                    $workgroupId,
                    $tenantId,
                    $input->name,
                    $input->description,
                    $actorUserId,
                );
                $this->audit->record(
                    eventType: 'WORKGROUP_CREATED',
                    outcome: 'SUCCESS',
                    reasonCode: 'WORKGROUP_CREATED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'workgroup_id' => $workgroupId,
                        'name' => $input->name,
                    ],
                );

                return $this->reload($tenantId, $workgroupId);
            },
        );
    }

    public function changeStatus(
        string $tenantId,
        string $workgroupId,
        WorkgroupStatus $targetStatus,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): WorkgroupDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $workgroupId,
                $targetStatus,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): WorkgroupDetails {
                $workgroup = $this->workgroups->findForTenant(
                    $tenantId,
                    $workgroupId,
                    true,
                );

                if ($workgroup === null) {
                    throw $this->workgroupNotFound();
                }

                if ($workgroup->status === $targetStatus) {
                    return $workgroup;
                }

                if (!$workgroup->status->canTransitionTo($targetStatus)) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'WORKGROUP_STATUS_TRANSITION_INVALID',
                        'The requested workgroup status transition is not allowed.',
                    );
                }

                $this->workgroups->changeStatus(
                    $tenantId,
                    $workgroupId,
                    $targetStatus,
                );
                $this->audit->record(
                    eventType: $targetStatus === WorkgroupStatus::Archived
                        ? 'WORKGROUP_ARCHIVED'
                        : 'WORKGROUP_REACTIVATED',
                    outcome: 'SUCCESS',
                    reasonCode: $targetStatus === WorkgroupStatus::Archived
                        ? 'WORKGROUP_ARCHIVED'
                        : 'WORKGROUP_REACTIVATED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: ['workgroup_id' => $workgroupId],
                );

                return $this->reload($tenantId, $workgroupId);
            },
        );
    }

    /**
     * @return list<WorkgroupMemberDetails>
     */
    public function listMembers(string $tenantId, string $workgroupId): array
    {
        if ($this->workgroups->findForTenant($tenantId, $workgroupId) === null) {
            throw $this->workgroupNotFound();
        }

        return $this->workgroups->listMembers($tenantId, $workgroupId);
    }

    public function upsertMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
        WorkgroupMemberRole $role,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): WorkgroupMemberDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $workgroupId,
                $membershipId,
                $role,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): WorkgroupMemberDetails {
                $workgroup = $this->workgroups->findForTenant(
                    $tenantId,
                    $workgroupId,
                    true,
                );

                if ($workgroup === null || $workgroup->status !== WorkgroupStatus::Active) {
                    throw $this->workgroupNotFound();
                }

                if ($this->workgroups->membershipStatus(
                    $tenantId,
                    $membershipId,
                ) !== 'ACTIVE') {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'WORKGROUP_MEMBERSHIP_INACTIVE',
                        'Only an active tenant membership can join a workgroup.',
                    );
                }

                $existingRole = $this->workgroups->memberRole(
                    $tenantId,
                    $workgroupId,
                    $membershipId,
                    true,
                );

                if ($existingRole === $role) {
                    return $this->reloadMember(
                        $tenantId,
                        $workgroupId,
                        $membershipId,
                    );
                }

                $this->workgroups->upsertMember(
                    $tenantId,
                    $workgroupId,
                    $membershipId,
                    $role,
                    $actorUserId,
                );
                $this->audit->record(
                    eventType: $existingRole === null
                        ? 'WORKGROUP_MEMBER_ADDED'
                        : 'WORKGROUP_MEMBER_ROLE_CHANGED',
                    outcome: 'SUCCESS',
                    reasonCode: $existingRole === null
                        ? 'WORKGROUP_MEMBER_ADDED'
                        : 'WORKGROUP_MEMBER_ROLE_CHANGED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'workgroup_id' => $workgroupId,
                        'membership_id' => $membershipId,
                        'role' => $role->value,
                    ],
                );

                return $this->reloadMember(
                    $tenantId,
                    $workgroupId,
                    $membershipId,
                );
            },
        );
    }

    public function removeMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->connection->transactional(
            function () use (
                $tenantId,
                $workgroupId,
                $membershipId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): void {
                if ($this->workgroups->findForTenant(
                    $tenantId,
                    $workgroupId,
                    true,
                ) === null) {
                    throw $this->workgroupNotFound();
                }

                if ($this->workgroups->memberRole(
                    $tenantId,
                    $workgroupId,
                    $membershipId,
                    true,
                ) === null) {
                    return;
                }

                $this->workgroups->removeMember(
                    $tenantId,
                    $workgroupId,
                    $membershipId,
                );
                $this->audit->record(
                    eventType: 'WORKGROUP_MEMBER_REMOVED',
                    outcome: 'SUCCESS',
                    reasonCode: 'WORKGROUP_MEMBER_REMOVED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'workgroup_id' => $workgroupId,
                        'membership_id' => $membershipId,
                    ],
                );
            },
        );
    }

    private function reload(
        string $tenantId,
        string $workgroupId,
    ): WorkgroupDetails {
        $workgroup = $this->workgroups->findForTenant($tenantId, $workgroupId);

        if ($workgroup === null) {
            throw new RuntimeException(
                'The updated workgroup could not be loaded.',
            );
        }

        return $workgroup;
    }

    private function reloadMember(
        string $tenantId,
        string $workgroupId,
        string $membershipId,
    ): WorkgroupMemberDetails {
        foreach (
            $this->workgroups->listMembers($tenantId, $workgroupId) as $member
        ) {
            if ($member->membershipId === $membershipId) {
                return $member;
            }
        }

        throw new RuntimeException(
            'The updated workgroup member could not be loaded.',
        );
    }

    private function workgroupNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'WORKGROUP_NOT_FOUND',
            'The workgroup was not found.',
        );
    }
}
