<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use Sova\Authorization\Domain\Permission;
use Sova\Authorization\Domain\PermissionScope;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final class TenantRoleDefinitionValidator
{
    /**
     * @param array<mixed> $payload
     */
    public function forCreate(array $payload): TenantRoleDefinitionInput
    {
        $errors = $this->unknownFields(
            $payload,
            ['code', 'name', 'description', 'permissions'],
        );
        $code = $this->code($payload['code'] ?? null, $errors);
        $name = $this->name($payload['name'] ?? null, $errors);
        $description = $this->description(
            $payload['description'] ?? '',
            $errors,
        );
        $permissions = $this->permissions(
            $payload['permissions'] ?? null,
            $errors,
        );
        $this->throwIfInvalid($errors);

        return TenantRoleDefinitionInput::forCreate(
            $code,
            $name,
            $description,
            $permissions,
        );
    }

    /**
     * @param array<mixed> $payload
     */
    public function forUpdate(array $payload): TenantRoleDefinitionInput
    {
        $errors = $this->unknownFields(
            $payload,
            ['name', 'description', 'permissions', 'revision'],
        );
        $name = $this->name($payload['name'] ?? null, $errors);
        $description = $this->description(
            $payload['description'] ?? null,
            $errors,
        );
        $permissions = $this->permissions(
            $payload['permissions'] ?? null,
            $errors,
        );
        $revision = $this->revision(
            $payload['revision'] ?? null,
            $errors,
        );
        $this->throwIfInvalid($errors);

        return TenantRoleDefinitionInput::forUpdate(
            $name,
            $description,
            $permissions,
            $revision,
        );
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $allowed
     *
     * @return array<string, list<string>>
     */
    private function unknownFields(array $payload, array $allowed): array
    {
        $errors = [];

        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                $errors['body'][] = 'The request contains an unknown field.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function code(mixed $value, array &$errors): string
    {
        if (!is_string($value)) {
            $errors['code'][] = 'Enter a role code.';

            return '';
        }

        $code = trim($value);

        if (preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $code) !== 1) {
            $errors['code'][] = 'Use 2-64 uppercase letters, digits, or underscores.';
        }

        return $code;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function name(mixed $value, array &$errors): string
    {
        if (!is_string($value)) {
            $errors['name'][] = 'Enter a role name.';

            return '';
        }

        $name = trim($value);

        if ($name === '') {
            $errors['name'][] = 'Enter a role name.';
        } elseif (strlen($name) > 160) {
            $errors['name'][] = 'Use at most 160 characters.';
        }

        return $name;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function description(mixed $value, array &$errors): string
    {
        if (!is_string($value)) {
            $errors['description'][] = 'Enter a role description.';

            return '';
        }

        $description = trim($value);

        if (strlen($description) > 500) {
            $errors['description'][] = 'Use at most 500 characters.';
        }

        return $description;
    }

    /**
     * @param array<string, list<string>> $errors
     *
     * @return list<Permission>
     */
    private function permissions(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors['permissions'][] = 'Provide a list of permission codes.';

            return [];
        }

        $selected = [];

        foreach ($value as $code) {
            if (!is_string($code)) {
                $errors['permissions'][] = 'Every permission code must be a string.';

                continue;
            }

            $permission = Permission::tryFrom($code);

            if (
                $permission === null
                || $permission->scope() === PermissionScope::System
            ) {
                $errors['permissions'][] = sprintf(
                    'Permission "%s" cannot be used by a tenant role.',
                    $code,
                );

                continue;
            }

            if (isset($selected[$permission->value])) {
                $errors['permissions'][] = sprintf(
                    'Permission "%s" is duplicated.',
                    $permission->value,
                );

                continue;
            }

            $selected[$permission->value] = $permission;
        }

        foreach ($selected as $permission) {
            foreach ($permission->dependencies() as $dependency) {
                if (!isset($selected[$dependency->value])) {
                    $errors['permissions'][] = sprintf(
                        'Permission "%s" requires "%s".',
                        $permission->value,
                        $dependency->value,
                    );
                }
            }
        }

        return array_values(array_filter(
            Permission::cases(),
            static fn(Permission $permission): bool => isset(
                $selected[$permission->value],
            ),
        ));
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function revision(mixed $value, array &$errors): int
    {
        if (!is_int($value) || $value < 1) {
            $errors['revision'][] = 'Provide a positive role revision.';

            return 1;
        }

        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::ValidationFailed,
            'TENANT_ROLE_INPUT_INVALID',
            'The tenant role input is invalid.',
            $errors,
        );
    }
}
