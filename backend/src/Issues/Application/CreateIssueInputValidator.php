<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use InvalidArgumentException;
use Sova\Issues\Domain\IssuePriority;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final class CreateIssueInputValidator
{
    private const ALLOWED_FIELDS = [
        'issue_type_id',
        'title',
        'description',
        'parent_issue_id',
        'assignee_membership_id',
        'assignee_workgroup_id',
        'priority',
    ];

    /**
     * @param array<mixed> $payload
     */
    public function validate(array $payload): CreateIssueInput
    {
        $errors = $this->unknownFields($payload);
        $issueTypeId = $this->requiredIdentifier(
            $payload['issue_type_id'] ?? null,
            'issue_type_id',
            $errors,
        );
        $title = $this->title($payload['title'] ?? null, $errors);
        $description = $this->description($payload['description'] ?? '', $errors);
        $parentIssueId = $this->optionalIdentifier(
            $payload['parent_issue_id'] ?? null,
            'parent_issue_id',
            $errors,
        );
        $assigneeMembershipId = $this->optionalIdentifier(
            $payload['assignee_membership_id'] ?? null,
            'assignee_membership_id',
            $errors,
        );
        $assigneeWorkgroupId = $this->optionalIdentifier(
            $payload['assignee_workgroup_id'] ?? null,
            'assignee_workgroup_id',
            $errors,
        );
        $priority = $this->priority($payload['priority'] ?? null, $errors);

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ISSUE_INPUT_INVALID',
                'The issue input is invalid.',
                $errors,
            );
        }

        return new CreateIssueInput(
            $issueTypeId,
            $title,
            $description,
            $parentIssueId,
            $assigneeMembershipId,
            $assigneeWorkgroupId,
            $priority,
        );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function title(mixed $value, array &$errors): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
            $errors['title'][] = 'Provide a title up to 255 characters.';

            return '';
        }

        return trim($value);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function description(mixed $value, array &$errors): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_string($value) || mb_strlen($value) > 20000) {
            $errors['description'][] = 'Use at most 20000 characters.';

            return '';
        }

        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function priority(mixed $value, array &$errors): IssuePriority
    {
        if ($value === null || $value === '') {
            return IssuePriority::Normal;
        }

        $priority = is_string($value) ? IssuePriority::tryFrom($value) : null;

        if ($priority === null) {
            $errors['priority'][] = 'Use one of: LOW, NORMAL, HIGH, CRITICAL.';

            return IssuePriority::Normal;
        }

        return $priority;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function requiredIdentifier(
        mixed $value,
        string $field,
        array &$errors,
    ): string {
        $identifier = $this->optionalIdentifier($value, $field, $errors);

        if ($identifier === null && !array_key_exists($field, $errors)) {
            $errors[$field][] = 'Provide a valid identifier.';
        }

        return $identifier ?? '';
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function optionalIdentifier(
        mixed $value,
        string $field,
        array &$errors,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (string) UuidV7::fromString(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            $errors[$field][] = 'Provide a valid identifier.';

            return null;
        }
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function unknownFields(array $payload): array
    {
        $errors = [];

        foreach (array_keys($payload) as $field) {
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                $errors['body'][] = 'The request contains an unknown field.';
            }
        }

        return $errors;
    }
}
