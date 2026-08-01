<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http;

use Sova\Issues\Application\IssueDetails;

final class IssueSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(IssueDetails $issue): array
    {
        return [
            'id' => $issue->id,
            'tenant_id' => $issue->tenantId,
            'project_id' => $issue->projectId,
            'number' => $issue->number,
            'key' => $issue->key,
            'title' => $issue->title,
            'description' => $issue->description,
            'issue_type' => [
                'id' => $issue->issueTypeId,
                'code' => $issue->issueTypeCode,
                'name' => $issue->issueTypeName,
            ],
            'workflow_version_id' => $issue->workflowVersionId,
            'status' => [
                'id' => $issue->statusId,
                'code' => $issue->statusCode,
                'name' => $issue->statusName,
                'category' => $issue->statusCategory->value,
            ],
            'parent' => $issue->parentIssueId === null ? null : [
                'id' => $issue->parentIssueId,
                'key' => $issue->parentIssueKey,
            ],
            'reporter' => [
                'membership_id' => $issue->reporterMembershipId,
                'display_name' => $issue->reporterDisplayName,
            ],
            'assignee' => $issue->assigneeMembershipId === null ? null : [
                'membership_id' => $issue->assigneeMembershipId,
                'display_name' => $issue->assigneeDisplayName,
            ],
            'assignee_workgroup' => $issue->assigneeWorkgroupId === null ? null : [
                'workgroup_id' => $issue->assigneeWorkgroupId,
                'name' => $issue->assigneeWorkgroupName,
            ],
            'priority' => $issue->priority->value,
            'resolution' => $issue->resolution,
            'resolved_at' => $issue->resolvedAt?->format(DATE_ATOM),
            'version' => $issue->version,
            'created_at' => $issue->createdAt->format(DATE_ATOM),
            'updated_at' => $issue->updatedAt->format(DATE_ATOM),
        ];
    }
}
