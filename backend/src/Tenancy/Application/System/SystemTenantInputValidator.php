<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final class SystemTenantInputValidator
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function validate(array $payload): SystemTenantInput
    {
        $errors = $this->unknownFields($payload);
        $name = $this->string($payload, 'name');
        $slug = $this->string($payload, 'slug');
        $ownerEmail = strtolower($this->string($payload, 'owner_email'));

        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = ['Name must contain between 1 and 200 characters.'];
        }

        if (
            preg_match(
                '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                $slug,
            ) !== 1
        ) {
            $errors['slug'] = ['Slug must contain lowercase letters, digits and hyphens.'];
        }

        if (
            strlen($ownerEmail) > 254
            || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['owner_email'] = ['Enter a valid owner email address.'];
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'SYSTEM_TENANT_INPUT_INVALID',
                'The tenant input is invalid.',
                $errors,
            );
        }

        return new SystemTenantInput($name, $slug, $ownerEmail);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function unknownFields(array $payload): array
    {
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, ['name', 'slug', 'owner_email'], true)) {
                return ['body' => ['The request contains an unknown field.']];
            }
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
