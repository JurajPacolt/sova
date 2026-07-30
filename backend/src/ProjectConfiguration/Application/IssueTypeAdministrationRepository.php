<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\HierarchyLevel;

interface IssueTypeAdministrationRepository
{
    public function findForUpdate(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): ?IssueTypeDetails;

    public function workflowCanServeActiveType(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): bool;

    public function create(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        CreateIssueTypeInput $input,
    ): void;

    public function update(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        UpdateIssueTypeInput $input,
    ): bool;

    public function archive(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        int $expectedTypeVersion,
    ): bool;

    public function hierarchyChangeIsValid(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        HierarchyLevel $targetLevel,
    ): bool;
}
