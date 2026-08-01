<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

use Sova\Projects\Domain\ProjectVisibility;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final class CreateProjectInputValidator
{
    /**
     * @param array<mixed> $payload
     */
    public function validate(array $payload): CreateProjectInput
    {
        $errors = $this->unknownFields($payload);
        $code = $this->code($payload['code'] ?? null, $errors);
        $name = $this->name($payload['name'] ?? null, $errors);
        $description = $this->description($payload['description'] ?? '', $errors);
        $visibility = $this->visibility($payload['visibility'] ?? null, $errors);
        $leadMembershipId = $this->leadMembershipId(
            $payload['lead_membership_id'] ?? null,
            $errors,
        );

        if (
            $visibility === ProjectVisibility::Private
            && $leadMembershipId === null
            && !array_key_exists('lead_membership_id', $errors)
        ) {
            $errors['lead_membership_id'][] = 'A private project requires a lead as its first manager.';
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'PROJECT_INPUT_INVALID',
                'The project input is invalid.',
                $errors,
            );
        }

        return new CreateProjectInput(
            $code,
            $name,
            $description,
            $visibility,
            $leadMembershipId,
        );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function code(mixed $value, array &$errors): string
    {
        if (!is_string($value) || trim($value) === '') {
            $errors['code'][] = 'Provide a project code.';

            return '';
        }

        $normalized = strtoupper(trim($value));

        if (preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $normalized) !== 1) {
            $errors['code'][] = 'Use 2 to 10 uppercase letters or digits, starting with a letter.';

            return '';
        }

        return $normalized;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function name(mixed $value, array &$errors): string
    {
        if (
            !is_string($value)
            || trim($value) === ''
            || mb_strlen($value) > 160
        ) {
            $errors['name'][] = 'Provide a name up to 160 characters.';

            return '';
        }

        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function description(mixed $value, array &$errors): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_string($value) || mb_strlen($value) > 500) {
            $errors['description'][] = 'Use at most 500 characters.';

            return '';
        }

        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function visibility(
        mixed $value,
        array &$errors,
    ): ProjectVisibility {
        if ($value === null || $value === '') {
            return ProjectVisibility::Tenant;
        }

        $visibility = is_string($value)
            ? ProjectVisibility::tryFrom($value)
            : null;

        if ($visibility === null) {
            $errors['visibility'][] = 'Use one of: TENANT, PRIVATE.';

            return ProjectVisibility::Tenant;
        }

        return $visibility;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function leadMembershipId(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (string) UuidV7::fromString(is_string($value) ? $value : '');
        } catch (\InvalidArgumentException) {
            $errors['lead_membership_id'][] = 'Provide a valid membership ID.';

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
        $allowed = ['code', 'name', 'description', 'visibility', 'lead_membership_id'];

        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $allowed, true)) {
                $errors['body'][] = 'The request contains an unknown field.';
            }
        }

        return $errors;
    }
}
