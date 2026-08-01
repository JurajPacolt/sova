<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final class CreateWorkgroupInputValidator
{
    /**
     * @param array<mixed> $payload
     */
    public function validate(array $payload): CreateWorkgroupInput
    {
        $errors = $this->unknownFields($payload);
        $name = $this->name($payload['name'] ?? null, $errors);
        $description = $this->description(
            $payload['description'] ?? '',
            $errors,
        );

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WORKGROUP_INPUT_INVALID',
                'The workgroup input is invalid.',
                $errors,
            );
        }

        return new CreateWorkgroupInput($name, $description);
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
     * @param array<mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function unknownFields(array $payload): array
    {
        $errors = [];

        foreach (array_keys($payload) as $field) {
            if ($field !== 'name' && $field !== 'description') {
                $errors['body'][] = 'The request contains an unknown field.';
            }
        }

        return $errors;
    }
}
