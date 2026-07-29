<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http;

use Sova\Issues\Application\Search\IssueQueryValidation;
use Sova\Issues\Application\Search\SearchOutcome;
use Sova\Issues\Application\Search\SearchResultItem;
use Sova\Issues\Domain\QueryLanguage\BasicCondition;
use Sova\Issues\Domain\QueryLanguage\BasicForm;
use Sova\Issues\Domain\QueryLanguage\BasicSort;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\QueryLimits;

final readonly class SearchSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serializeOutcome(SearchOutcome $outcome): array
    {
        return [
            'issues' => array_map($this->serializeItem(...), $outcome->items),
            'canonical_query' => $outcome->canonicalQuery,
            'page_size' => $outcome->pageSize,
            'next_cursor' => $outcome->nextCursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeValidation(IssueQueryValidation $validation): array
    {
        return [
            'valid' => $validation->valid,
            'canonical_query' => $validation->canonical,
            'errors' => array_map(
                static fn($error): array => $error->jsonSerialize(),
                $validation->errors,
            ),
            'basic_form' => $validation->basicForm === null
                ? null
                : $this->serializeBasicForm($validation->basicForm),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBasicForm(BasicForm $form): array
    {
        return [
            'representable' => $form->representable,
            'conditions' => array_map(
                static fn(BasicCondition $condition): array => [
                    'field' => $condition->field,
                    'operator' => $condition->operator,
                    'values' => $condition->values,
                ],
                $form->conditions,
            ),
            'sort' => array_map(
                static fn(BasicSort $sort): array => [
                    'field' => $sort->field,
                    'direction' => $sort->direction,
                    'nulls' => $sort->nulls,
                ],
                $form->sort,
            ),
        ];
    }

    /**
     * The editor needs the active limits and the field list to guide the user
     * before a request is rejected (spec §4.12).
     *
     * @return array<string, mixed>
     */
    public function serializeMetadata(FieldCatalog $fields, QueryLimits $limits): array
    {
        $supported = [];

        foreach ($fields->supported() as $field) {
            $supported[] = [
                'name' => $field->canonicalName,
                // A pure enum: the case name is the stable wire value.
                'type' => $field->type->name,
                'operators' => array_map(
                    static fn($operator): string => $operator->value,
                    $field->comparisons,
                ),
                'supports_set' => $field->allowsSet,
                'supports_empty' => $field->allowsEmpty,
                'sortable' => $field->sortable,
            ];
        }

        return [
            'fields' => $supported,
            'limits' => [
                'max_query_bytes' => $limits->maxQueryBytes,
                'max_ast_nodes' => $limits->maxAstNodes,
                'max_paren_depth' => $limits->maxParenDepth,
                'max_in_values' => $limits->maxInValues,
                'max_sort_fields' => $limits->maxSortFields,
                'default_page_size' => $limits->defaultPageSize,
                'max_page_size' => $limits->maxPageSize,
                'statement_timeout_ms' => $limits->statementTimeoutMs,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(SearchResultItem $item): array
    {
        return [
            'id' => $item->id,
            'key' => $item->key,
            'title' => $item->title,
            'project' => [
                'id' => $item->projectId,
                'code' => $item->projectCode,
                'name' => $item->projectName,
            ],
            'issue_type' => [
                'code' => $item->issueTypeCode,
                'name' => $item->issueTypeName,
                'hierarchy_level' => $item->hierarchyLevel,
            ],
            'status' => [
                'code' => $item->statusCode,
                'name' => $item->statusName,
                'category' => $item->statusCategory,
            ],
            'priority' => $item->priority,
            'assignee' => $item->assigneeMembershipId === null ? null : [
                'membership_id' => $item->assigneeMembershipId,
                'display_name' => $item->assigneeDisplayName,
            ],
            'assignee_workgroup' => $item->assigneeWorkgroupId === null ? null : [
                'workgroup_id' => $item->assigneeWorkgroupId,
                'name' => $item->assigneeWorkgroupName,
            ],
            'parent_key' => $item->parentIssueKey,
            'resolution' => $item->resolution,
            'resolved_at' => $item->resolvedAt?->format(DATE_ATOM),
            'created_at' => $item->createdAt->format(DATE_ATOM),
            'updated_at' => $item->updatedAt->format(DATE_ATOM),
        ];
    }
}
