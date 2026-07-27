<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Doctrine\DBAL\Connection;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class ProjectWorkgroupService
{
    public function __construct(
        private Connection $connection,
        private ProjectRepository $projects,
        private ProjectRoleRepository $roles,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @return list<ProjectWorkgroupLinkDetails>
     */
    public function list(string $tenantId, string $projectId): array
    {
        $this->existingProject($tenantId, $projectId);

        return $this->roles->listWorkgroupLinks($tenantId, $projectId);
    }

    public function link(
        string $tenantId,
        string $projectId,
        string $workgroupId,
        string $roleId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $workgroupId,
                $roleId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): void {
                $this->activeProject($tenantId, $projectId);

                if ($this->roles->workgroupStatus($tenantId, $workgroupId) !== 'ACTIVE') {
                    throw new DomainProblemException(
                        ProblemType::ResourceNotFound,
                        'WORKGROUP_NOT_FOUND',
                        'The workgroup was not found.',
                    );
                }

                $role = $this->activeRole($tenantId, $projectId, $roleId);
                $exists = $this->roles->workgroupLinkExists(
                    $tenantId,
                    $projectId,
                    $workgroupId,
                );
                $this->roles->linkWorkgroup(
                    $tenantId,
                    $projectId,
                    $workgroupId,
                    $roleId,
                    $actorUserId,
                );
                $this->audit->record(
                    eventType: $exists
                        ? 'PROJECT_WORKGROUP_ROLE_CHANGED'
                        : 'PROJECT_WORKGROUP_LINKED',
                    outcome: 'SUCCESS',
                    reasonCode: $exists
                        ? 'PROJECT_WORKGROUP_ROLE_CHANGED'
                        : 'PROJECT_WORKGROUP_LINKED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'project_id' => $projectId,
                        'workgroup_id' => $workgroupId,
                        'role_id' => $roleId,
                        'role_code' => $role->code,
                    ],
                );
            },
        );
    }

    public function unlink(
        string $tenantId,
        string $projectId,
        string $workgroupId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $workgroupId,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): void {
                $this->existingProject($tenantId, $projectId);

                if (!$this->roles->workgroupLinkExists(
                    $tenantId,
                    $projectId,
                    $workgroupId,
                )) {
                    return;
                }

                $this->roles->unlinkWorkgroup($tenantId, $projectId, $workgroupId);
                $this->audit->record(
                    eventType: 'PROJECT_WORKGROUP_UNLINKED',
                    outcome: 'SUCCESS',
                    reasonCode: 'PROJECT_WORKGROUP_UNLINKED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'project_id' => $projectId,
                        'workgroup_id' => $workgroupId,
                    ],
                );
            },
        );
    }

    private function existingProject(
        string $tenantId,
        string $projectId,
    ): ProjectDetails {
        $project = $this->projects->findForTenant($tenantId, $projectId);

        if ($project === null) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'PROJECT_NOT_FOUND',
                'The project was not found.',
            );
        }

        return $project;
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

    private function activeRole(
        string $tenantId,
        string $projectId,
        string $roleId,
    ): ProjectRoleDetails {
        $role = $this->roles->findForProject($tenantId, $projectId, $roleId, true);

        if ($role === null) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'PROJECT_ROLE_NOT_FOUND',
                'The project role was not found.',
            );
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
}
