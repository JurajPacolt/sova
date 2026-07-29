<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Sova\Authorization\Domain\DefaultRole;
use Sova\ProjectConfiguration\Application\ProjectConfigurationProvisioner;
use Sova\Projects\Domain\ProjectStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class ProjectAdministrationService
{
    public function __construct(
        private Connection $connection,
        private ProjectRepository $projects,
        private ProjectRoleProvisioner $roleProvisioner,
        private ProjectRoleRepository $roles,
        private ProjectConfigurationProvisioner $configurationProvisioner,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * Every project of the tenant, for callers holding `tenant.projects.manage`.
     *
     * @return list<ProjectListItem>
     */
    public function list(string $tenantId, string $viewerUserId): array
    {
        return $this->projects->listForTenant($tenantId, $viewerUserId);
    }

    /**
     * Only what the user may see: tenant-visible projects plus private ones
     * they reach through a role assignment or a linked workgroup.
     *
     * @return list<ProjectListItem>
     */
    public function listVisible(string $tenantId, string $userId): array
    {
        return $this->projects->listVisibleForUser($tenantId, $userId);
    }

    public function create(
        string $tenantId,
        CreateProjectInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): ProjectDetails {
        try {
            return $this->connection->transactional(
                function () use (
                    $tenantId,
                    $input,
                    $actorUserId,
                    $requestId,
                    $ipAddress,
                ): ProjectDetails {
                    if ($input->leadMembershipId !== null) {
                        $status = $this->projects->membershipStatus(
                            $tenantId,
                            $input->leadMembershipId,
                        );

                        if ($status !== 'ACTIVE') {
                            throw new DomainProblemException(
                                ProblemType::Conflict,
                                'PROJECT_LEAD_MEMBERSHIP_INACTIVE',
                                'The project lead must be an active tenant member.',
                            );
                        }
                    }

                    $projectId = (string) UuidV7::generate();
                    $this->projects->create(
                        $projectId,
                        $tenantId,
                        $input->code,
                        $input->name,
                        $input->description,
                        $input->visibility,
                        $input->leadMembershipId,
                        $actorUserId,
                    );
                    $this->roleProvisioner->provisionDefaults(
                        $tenantId,
                        $projectId,
                        $actorUserId,
                    );
                    // Same transaction: a project must never exist without
                    // issue types, statuses and a published workflow.
                    $this->configurationProvisioner->provisionDefaults(
                        $tenantId,
                        $projectId,
                        $actorUserId,
                    );

                    if ($input->leadMembershipId !== null) {
                        $managerRole = $this->roles->findByCode(
                            $tenantId,
                            $projectId,
                            DefaultRole::ProjectManager->value,
                        );

                        if ($managerRole === null) {
                            throw new RuntimeException(
                                'The default project manager role was not provisioned.',
                            );
                        }

                        $this->roles->assign(
                            $tenantId,
                            $projectId,
                            $input->leadMembershipId,
                            $managerRole->id,
                            $actorUserId,
                        );
                    }

                    $this->audit->record(
                        eventType: 'PROJECT_CREATED',
                        outcome: 'SUCCESS',
                        reasonCode: 'PROJECT_CREATED',
                        requestId: $requestId,
                        actorUserId: $actorUserId,
                        tenantId: $tenantId,
                        ipAddress: $ipAddress,
                        metadata: [
                            'project_id' => $projectId,
                            'code' => $input->code,
                            'visibility' => $input->visibility->value,
                        ],
                    );

                    return $this->reload($tenantId, $projectId);
                },
            );
        } catch (UniqueConstraintViolationException) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'PROJECT_CODE_TAKEN',
                'A project with this code already exists. Choose a different one.',
            );
        }
    }

    public function changeStatus(
        string $tenantId,
        string $projectId,
        ProjectStatus $targetStatus,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): ProjectDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $targetStatus,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): ProjectDetails {
                $project = $this->projects->findForTenant(
                    $tenantId,
                    $projectId,
                    true,
                );

                if ($project === null) {
                    throw $this->projectNotFound();
                }

                if ($project->status === $targetStatus) {
                    return $project;
                }

                if (!$project->status->canTransitionTo($targetStatus)) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'PROJECT_STATUS_TRANSITION_INVALID',
                        'The requested project status transition is not allowed.',
                    );
                }

                $this->projects->changeStatus($tenantId, $projectId, $targetStatus);
                $this->audit->record(
                    eventType: $targetStatus === ProjectStatus::Archived
                        ? 'PROJECT_ARCHIVED'
                        : 'PROJECT_REACTIVATED',
                    outcome: 'SUCCESS',
                    reasonCode: $targetStatus === ProjectStatus::Archived
                        ? 'PROJECT_ARCHIVED'
                        : 'PROJECT_REACTIVATED',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: ['project_id' => $projectId],
                );

                return $this->reload($tenantId, $projectId);
            },
        );
    }

    private function reload(string $tenantId, string $projectId): ProjectDetails
    {
        $project = $this->projects->findForTenant($tenantId, $projectId);

        if ($project === null) {
            throw new RuntimeException('The updated project could not be loaded.');
        }

        return $project;
    }

    private function projectNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'PROJECT_NOT_FOUND',
            'The project was not found.',
        );
    }
}
