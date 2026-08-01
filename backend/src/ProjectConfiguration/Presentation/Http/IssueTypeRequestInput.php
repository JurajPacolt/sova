<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Presentation\Http;

use InvalidArgumentException;
use Sova\ProjectConfiguration\Application\CreateIssueTypeInput;
use Sova\ProjectConfiguration\Application\UpdateIssueTypeInput;
use Sova\ProjectConfiguration\Domain\HierarchyLevel;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final class IssueTypeRequestInput
{
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,31}$/';
    private const TOKEN_PATTERN = '/^[a-z0-9-]{0,48}$/';

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): CreateIssueTypeInput
    {
        $this->onlyFields($payload, [
            'code',
            'name',
            'description',
            'hierarchy_level',
            'position',
            'icon',
            'color_token',
            'workflow_id',
            'expected_config_version',
        ]);

        return new CreateIssueTypeInput(
            code: $this->code($payload['code'] ?? null),
            name: $this->name($payload['name'] ?? null),
            description: $this->description($payload['description'] ?? ''),
            hierarchyLevel: $this->hierarchyLevel($payload['hierarchy_level'] ?? null),
            position: $this->position($payload['position'] ?? null),
            icon: $this->token($payload['icon'] ?? '', 'icon'),
            colorToken: $this->token($payload['color_token'] ?? '', 'color_token'),
            workflowId: $this->identifier($payload['workflow_id'] ?? null, 'workflow_id'),
            expectedConfigVersion: $this->positiveInteger(
                $payload['expected_config_version'] ?? null,
                'expected_config_version',
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(array $payload): UpdateIssueTypeInput
    {
        $this->onlyFields($payload, [
            'name',
            'description',
            'hierarchy_level',
            'position',
            'icon',
            'color_token',
            'workflow_id',
            'expected_config_version',
            'expected_type_version',
        ]);

        return new UpdateIssueTypeInput(
            name: $this->name($payload['name'] ?? null),
            description: $this->description($payload['description'] ?? ''),
            hierarchyLevel: $this->hierarchyLevel($payload['hierarchy_level'] ?? null),
            position: $this->position($payload['position'] ?? null),
            icon: $this->token($payload['icon'] ?? '', 'icon'),
            colorToken: $this->token($payload['color_token'] ?? '', 'color_token'),
            workflowId: $this->identifier($payload['workflow_id'] ?? null, 'workflow_id'),
            expectedConfigVersion: $this->positiveInteger(
                $payload['expected_config_version'] ?? null,
                'expected_config_version',
            ),
            expectedTypeVersion: $this->positiveInteger(
                $payload['expected_type_version'] ?? null,
                'expected_type_version',
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{int, int}
     */
    public function archiveVersions(array $payload): array
    {
        $this->onlyFields($payload, [
            'expected_config_version',
            'expected_type_version',
        ]);

        return [
            $this->positiveInteger(
                $payload['expected_config_version'] ?? null,
                'expected_config_version',
            ),
            $this->positiveInteger(
                $payload['expected_type_version'] ?? null,
                'expected_type_version',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowed
     */
    private function onlyFields(array $payload, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($payload), $allowed));

        if ($unknown !== []) {
            throw $this->invalid(
                'body',
                sprintf('Unsupported field: %s.', implode(', ', $unknown)),
            );
        }
    }

    private function code(mixed $value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw $this->invalid(
                'code',
                'Use 2 to 32 uppercase letters, digits or underscores, starting with a letter.',
            );
        }

        return $code;
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '' || mb_strlen($name) > 120) {
            throw $this->invalid('name', 'Enter a name with at most 120 characters.');
        }

        return $name;
    }

    private function description(mixed $value): string
    {
        $description = is_string($value) ? trim($value) : '';

        if (mb_strlen($description) > 500) {
            throw $this->invalid(
                'description',
                'Enter a description with at most 500 characters.',
            );
        }

        return $description;
    }

    private function hierarchyLevel(mixed $value): HierarchyLevel
    {
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            $value = (int) $value;
        }

        $level = is_int($value) ? HierarchyLevel::tryFrom($value) : null;

        if ($level === null) {
            throw $this->invalid('hierarchy_level', 'Use one of: 1, 0, -1.');
        }

        return $level;
    }

    private function position(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 0 || $value > 10000) {
            throw $this->invalid('position', 'Use a position from 0 to 10000.');
        }

        return $value;
    }

    private function token(mixed $value, string $field): string
    {
        $token = is_string($value) ? trim($value) : '';

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw $this->invalid(
                $field,
                'Use at most 48 lowercase letters, digits or hyphens.',
            );
        }

        return $token;
    }

    private function identifier(mixed $value, string $field): string
    {
        try {
            return (string) UuidV7::fromString(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw $this->invalid($field, 'Choose a resource from this project.');
        }
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 1) {
            throw $this->invalid($field, 'Provide a positive current version.');
        }

        return $value;
    }

    private function invalid(string $field, string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'ISSUE_TYPE_INPUT_INVALID',
            'The issue type input is invalid.',
            [$field => [$message]],
        );
    }
}
