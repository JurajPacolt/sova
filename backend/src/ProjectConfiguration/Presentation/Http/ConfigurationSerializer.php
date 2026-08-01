<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http;

use Sova\ProjectConfiguration\Application\ConfigurationHistoryEntry;
use Sova\ProjectConfiguration\Application\ImpactReport;
use Sova\ProjectConfiguration\Application\IssueTypeDetails;
use Sova\ProjectConfiguration\Application\RuleView;
use Sova\ProjectConfiguration\Application\StatusDetails;
use Sova\ProjectConfiguration\Application\StatusIssueCount;
use Sova\ProjectConfiguration\Application\TransitionDetails;
use Sova\ProjectConfiguration\Application\TransitionView;
use Sova\ProjectConfiguration\Application\VersionStatusView;
use Sova\ProjectConfiguration\Application\WorkflowSummary;
use Sova\ProjectConfiguration\Application\WorkflowVersionView;
use Sova\ProjectConfiguration\Domain\WorkflowValidationError;

final class ConfigurationSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serializeIssueType(IssueTypeDetails $type): array
    {
        return [
            'id' => $type->id,
            'project_id' => $type->projectId,
            'code' => $type->code,
            'name' => $type->name,
            'description' => $type->description,
            'hierarchy_level' => $type->hierarchyLevel->value,
            'position' => $type->position,
            'icon' => $type->icon,
            'color_token' => $type->colorToken,
            'status' => $type->status->value,
            'version' => $type->version,
            'workflow_id' => $type->workflowId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeStatus(StatusDetails $status): array
    {
        return [
            'id' => $status->id,
            'project_id' => $status->projectId,
            'code' => $status->code,
            'name' => $status->name,
            'description' => $status->description,
            'category' => $status->category->value,
            'position' => $status->position,
            'status' => $status->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTransition(TransitionDetails $transition): array
    {
        return [
            'id' => $transition->id,
            'code' => $transition->code,
            'name' => $transition->name,
            'to_status' => [
                'id' => $transition->toStatusId,
                'code' => $transition->toStatusCode,
                'name' => $transition->toStatusName,
            ],
            'is_primary' => $transition->isPrimary,
            'position' => $transition->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeWorkflow(WorkflowSummary $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => $workflow->status->value,
            'active_version_id' => $workflow->activeVersionId,
            'published_version' => $workflow->publishedVersion === null
                ? null
                : $this->serializeVersion($workflow->publishedVersion),
            'draft_version' => $workflow->draftVersion === null
                ? null
                : $this->serializeVersion($workflow->draftVersion),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeVersion(WorkflowVersionView $version): array
    {
        return [
            'id' => $version->id,
            'workflow_id' => $version->workflowId,
            'version_number' => $version->versionNumber,
            'state' => $version->state->value,
            'version' => $version->optimisticVersion,
            'initial_status_id' => $version->initialStatusId,
            'statuses' => array_map(
                $this->serializeVersionStatus(...),
                $version->statuses,
            ),
            'transitions' => array_map(
                $this->serializeVersionTransition(...),
                $version->transitions,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeVersionStatus(VersionStatusView $status): array
    {
        return [
            'status_id' => $status->statusId,
            'code' => $status->code,
            'name' => $status->name,
            'category' => $status->category->value,
            'color_token' => $status->colorToken,
            'position' => $status->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeVersionTransition(TransitionView $transition): array
    {
        return [
            'id' => $transition->id,
            'code' => $transition->code,
            'name' => $transition->name,
            'from_status_id' => $transition->fromStatusId,
            'to_status_id' => $transition->toStatusId,
            'permission_code' => $transition->permissionCode,
            'is_primary' => $transition->isPrimary,
            'position' => $transition->position,
            'rules' => array_map($this->serializeRule(...), $transition->rules),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRule(RuleView $rule): array
    {
        return [
            'id' => $rule->id,
            'type' => $rule->ruleType->value,
            'key' => $rule->ruleKey,
            'configuration' => $rule->configuration,
            'position' => $rule->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeValidationError(WorkflowValidationError $error): array
    {
        return ['code' => $error->code, 'detail' => $error->detail];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeImpact(ImpactReport $report): array
    {
        return [
            'workflow_id' => $report->workflowId,
            'expected_config_version' => $report->expectedConfigVersion,
            'publishable' => $report->isPublishable(),
            'requires_migration' => $report->requiresMigration(),
            'validation_errors' => array_map(
                $this->serializeValidationError(...),
                $report->validationErrors,
            ),
            'type_codes_using_workflow' => $report->typeCodesUsingWorkflow,
            'added_status_codes' => $report->addedStatusCodes,
            'removed_status_codes' => $report->removedStatusCodes,
            'added_transition_codes' => $report->addedTransitionCodes,
            'removed_transition_codes' => $report->removedTransitionCodes,
            'affected_issue_counts' => array_map(
                $this->serializeStatusIssueCount(...),
                $report->affectedIssueCounts,
            ),
            'required_status_mapping_codes' => $report->requiredStatusMappingCodes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeStatusIssueCount(StatusIssueCount $count): array
    {
        return [
            'status_id' => $count->statusId,
            'status_code' => $count->statusCode,
            'status_name' => $count->statusName,
            'count' => $count->count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeHistoryEntry(ConfigurationHistoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'revision' => $entry->revision,
            'event_type' => $entry->eventType,
            'workflow_id' => $entry->workflowId,
            'workflow_version_id' => $entry->workflowVersionId,
            'actor_user_id' => $entry->actorUserId,
            'metadata' => $entry->metadata,
            'created_at' => $entry->createdAt,
        ];
    }
}
