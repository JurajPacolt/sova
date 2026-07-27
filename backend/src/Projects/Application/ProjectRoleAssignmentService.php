<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Doctrine\DBAL\Connection;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class ProjectRoleAssignmentService
{
    public function __construct(
        private Connection $connection,
        private ProjectRepository $projects,
        private ProjectRoleRepository $roles,
        private SecurityAuditRecorder $audit,
    ) {}

    public function assign(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $membershipId,
                $roleId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): void {
                $project = $this->activeProject($tenantId, $projectId);
                $this->assertActiveMembership($tenantId, $membershipId);
                $role = $this->activeRole($tenantId, $projectId, $roleId);

                if ($this->roles->assignmentExists(
                    $tenantId,
                    $projectId,
                    $membershipId,
                    $roleId,
                )) {
                    return;
                }

                $this->roles->assign(
                    $tenantId,
                    $projectId,
                    $membershipId,
                    $roleId,
                    $actorUserId,
                );
                $this->audit->record(
                    eventType: 'PROJECT_ROLE_ASSIGNED',
                    outcome: 'SUCCESS',
                    reasonCode: 'PROJECT_ROLE_ASSIGNED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'project_id' => $project->id,
                        'membership_id' => $membershipId,
                        'role_id' => $roleId,
                        'role_code' => $role->code,
                    ],
                );
            },
        );
    }

    public function unassign(
        string $tenantId,
        string $projectId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $membershipId,
                $roleId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): void {
                $project = $this->activeProject($tenantId, $projectId);
                $role = $this->roles->findForProject(
                    $tenantId,
                    $projectId,
                    $roleId,
                    true,
                );

                if ($role === null) {
                    throw $this->roleNotFound();
                }

                if (!$this->roles->assignmentExists(
                    $tenantId,
                    $projectId,
                    $membershipId,
                    $roleId,
                )) {
                    return;
                }

                $this->roles->unassign($tenantId, $projectId, $membershipId, $roleId);
                $this->audit->record(
                    eventType: 'PROJECT_ROLE_UNASSIGNED',
                    outcome: 'SUCCESS',
                    reasonCode: 'PROJECT_ROLE_UNASSIGNED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'project_id' => $project->id,
                        'membership_id' => $membershipId,
                        'role_id' => $roleId,
                        'role_code' => $role->code,
                    ],
                );
            },
        );
    }

    private function activeProject(
        string $tenantId,
        string $projectId,
    ): ProjectDetails {
        $project = $this->projects->findForTenant($tenantId, $projectId, true);

        if ($project === null || $project->status !== ProjectStatus::Active) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'PROJECT_NOT_FOUND',
                'The project was not found.',
            );
        }

        return $project;
    }

    private function assertActiveMembership(
        string $tenantId,
        string $membershipId,
    ): void {
        if ($this->projects->membershipStatus($tenantId, $membershipId) === 'ACTIVE') {
            return;
        }

        throw new DomainProblemException(
            ProblemType::Conflict,
            'PROJECT_MEMBERSHIP_INACTIVE',
            'Roles can be assigned only to an active tenant membership.',
        );
    }

    private function activeRole(
        string $tenantId,
        string $projectId,
        string $roleId,
    ): ProjectRoleDetails {
        $role = $this->roles->findForProject($tenantId, $projectId, $roleId, true);

        if ($role === null) {
            throw $this->roleNotFound();
        }

        if ($role->status !== 'ACTIVE') {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'PROJECT_ROLE_INACTIVE',
                'An archived project role cannot be assigned.',
            );
        }

        return $role;
    }

    private function roleNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'PROJECT_ROLE_NOT_FOUND',
            'The project role was not found.',
        );
    }
}
