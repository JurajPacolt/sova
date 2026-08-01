<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * The authoritative registry of SovaQL v1 fields. It is deliberately complete:
 * every field named in the specification is present, but the ones without a
 * backing column yet (`labels`, `due`, `estimate`, `closed`) are marked
 * unsupported so they light up automatically once their phase lands, without a
 * language version bump. `watcher` already made that journey. `summary` is a transitional alias of
 * `title`, and any dotted token (the reserved `cf.<key>` space) is refused as
 * unsupported rather than guessed at.
 */
final class FieldCatalog
{
    /** @var array<string, FieldDefinition> */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = $this->build();
    }

    /**
     * Returns the field's definition, resolving the `summary` alias, or null
     * when the name is not a v1 field at all (`QUERY_FIELD_UNKNOWN`). A dotted
     * name resolves to an unsupported placeholder (`QUERY_FIELD_NOT_SUPPORTED`).
     */
    public function definition(string $name): ?FieldDefinition
    {
        if (str_contains($name, '.')) {
            return new FieldDefinition(
                strtolower($name),
                FieldType::Label,
                false,
                [],
                false,
                false,
                false,
            );
        }

        $key = strtolower($name);

        if ($key === 'summary') {
            return $this->definitions['title'];
        }

        return $this->definitions[$key] ?? null;
    }

    /**
     * Supported fields for the metadata endpoint.
     *
     * @return list<FieldDefinition>
     */
    public function supported(): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn(FieldDefinition $field): bool => $field->supported,
        ));
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function build(): array
    {
        $equality = [ComparisonOperator::Equals, ComparisonOperator::NotEquals];
        $ordering = [
            ComparisonOperator::Equals,
            ComparisonOperator::NotEquals,
            ComparisonOperator::GreaterThan,
            ComparisonOperator::GreaterOrEqual,
            ComparisonOperator::LessThan,
            ComparisonOperator::LessOrEqual,
        ];
        $fulltext = [ComparisonOperator::Matches, ComparisonOperator::NotMatches];
        $title = [
            ComparisonOperator::Matches,
            ComparisonOperator::NotMatches,
            ComparisonOperator::Equals,
            ComparisonOperator::NotEquals,
        ];

        return [
            'key' => new FieldDefinition('key', FieldType::IssueKey, true, $equality, true, false, true),
            'project' => new FieldDefinition('project', FieldType::ProjectCode, true, $equality, true, false, true),
            'type' => new FieldDefinition('type', FieldType::IssueTypeCode, true, $equality, true, false, true),
            'hierarchylevel' => new FieldDefinition('hierarchyLevel', FieldType::Integer, true, $ordering, true, false, true),
            'status' => new FieldDefinition('status', FieldType::StatusCode, true, $equality, true, false, true),
            'statuscategory' => new FieldDefinition('statusCategory', FieldType::StatusCategory, true, $equality, true, false, true),
            'priority' => new FieldDefinition('priority', FieldType::Priority, true, $equality, true, false, true),
            'title' => new FieldDefinition('title', FieldType::Title, true, $title, false, false, true),
            'text' => new FieldDefinition('text', FieldType::Fulltext, true, $fulltext, false, false, false),
            'reporter' => new FieldDefinition('reporter', FieldType::User, true, $equality, true, true, false),
            'assignee' => new FieldDefinition('assignee', FieldType::User, true, $equality, true, true, false),
            'group' => new FieldDefinition('group', FieldType::Workgroup, true, $equality, true, true, false),
            'parent' => new FieldDefinition('parent', FieldType::IssueKey, true, $equality, true, true, false),
            'created' => new FieldDefinition('created', FieldType::DateTime, true, $ordering, false, false, true),
            'updated' => new FieldDefinition('updated', FieldType::DateTime, true, $ordering, false, false, true),
            'resolved' => new FieldDefinition('resolved', FieldType::DateTime, true, $ordering, false, true, true),
            // Storage landed with issue watchers, so the field lit up here
            // without a language version bump — exactly as promised above.
            'watcher' => new FieldDefinition('watcher', FieldType::User, true, $equality, true, false, false),
            'labels' => new FieldDefinition('labels', FieldType::Label, false, $equality, true, true, false),
            'due' => new FieldDefinition('due', FieldType::Date, false, $ordering, false, true, true),
            'estimate' => new FieldDefinition('estimate', FieldType::Duration, false, $ordering, false, true, true),
            'closed' => new FieldDefinition('closed', FieldType::DateTime, false, $ordering, false, true, true),
        ];
    }
}
