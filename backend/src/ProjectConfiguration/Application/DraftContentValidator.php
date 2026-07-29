<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\StatusCategory;
use Sova\ProjectConfiguration\Domain\TransitionRuleType;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

/**
 * Turns the raw draft payload into a validated {@see DraftContentInput}. It
 * enforces the structural contract — code patterns, required fields, known
 * enums, unique codes and transitions that only reference declared statuses —
 * so the graph rules in {@see WorkflowValidator} always run over a well-formed
 * version. Every structural problem is collected and reported at once.
 */
final readonly class DraftContentValidator
{
    private const STATUS_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,31}$/';
    private const TRANSITION_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,63}$/';
    private const RULE_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param array<string, mixed> $body
     */
    public function parse(array $body): DraftContentInput
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        $expectedVersion = $this->requiredInt($body, 'expected_version', $errors) ?? 0;
        $statuses = $this->parseStatuses($body, $errors);
        $declaredCodes = [];

        foreach ($statuses as $status) {
            $declaredCodes[$status->code] = true;
        }

        $initialStatusCode = $this->requiredString($body, 'initial_status_code', $errors) ?? '';

        if ($initialStatusCode !== '' && !isset($declaredCodes[$initialStatusCode])) {
            $errors['initial_status_code'][] = 'The initial status must be one of the statuses.';
        }

        $transitions = $this->parseTransitions($body, $declaredCodes, $errors);

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WORKFLOW_DRAFT_INVALID',
                'The workflow draft payload is malformed.',
                $errors,
            );
        }

        return new DraftContentInput(
            expectedVersion: $expectedVersion,
            initialStatusCode: $initialStatusCode,
            statuses: $statuses,
            transitions: $transitions,
        );
    }

    /**
     * @param array<string, mixed>        $body
     * @param array<string, list<string>> $errors
     *
     * @return list<DraftStatusInput>
     */
    private function parseStatuses(array $body, array &$errors): array
    {
        $rawStatuses = $this->listField($body, 'statuses', $errors);

        if ($rawStatuses === []) {
            $errors['statuses'][] = 'Provide at least one status.';

            return [];
        }

        $statuses = [];
        $seen = [];

        foreach ($rawStatuses as $index => $raw) {
            $path = sprintf('statuses[%d]', $index);

            if (!is_array($raw)) {
                $errors[$path][] = 'Each status must be an object.';

                continue;
            }

            /** @var array<string, mixed> $raw */
            $code = $this->requiredPattern($raw, 'code', self::STATUS_CODE_PATTERN, $path, $errors);
            $category = $this->requiredCategory($raw, $path, $errors);
            $name = $this->requiredString($raw, 'name', $errors, $path);

            if ($code === null || $category === null || $name === null) {
                continue;
            }

            if (isset($seen[$code])) {
                $errors[$path . '.code'][] = 'Status codes must be unique.';

                continue;
            }

            $seen[$code] = true;
            $statuses[] = new DraftStatusInput(
                code: $code,
                name: $name,
                description: $this->optionalString($raw, 'description'),
                category: $category,
                colorToken: $this->optionalString($raw, 'color_token'),
                position: $this->optionalInt($raw, 'position', $index),
            );
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed>        $body
     * @param array<string, true>         $declaredCodes
     * @param array<string, list<string>> $errors
     *
     * @return list<DraftTransitionInput>
     */
    private function parseTransitions(array $body, array $declaredCodes, array &$errors): array
    {
        $rawTransitions = $this->listField($body, 'transitions', $errors);
        $transitions = [];
        $seen = [];

        foreach ($rawTransitions as $index => $raw) {
            $path = sprintf('transitions[%d]', $index);

            if (!is_array($raw)) {
                $errors[$path][] = 'Each transition must be an object.';

                continue;
            }

            /** @var array<string, mixed> $raw */
            $code = $this->requiredPattern(
                $raw,
                'code',
                self::TRANSITION_CODE_PATTERN,
                $path,
                $errors,
            );
            $name = $this->requiredString($raw, 'name', $errors, $path);
            $from = $this->requiredMember($raw, 'from', $declaredCodes, $path, $errors);
            $to = $this->requiredMember($raw, 'to', $declaredCodes, $path, $errors);

            if ($code === null || $name === null || $from === null || $to === null) {
                continue;
            }

            if ($from === $to) {
                $errors[$path][] = 'A transition cannot start and end on the same status.';

                continue;
            }

            if (isset($seen[$code])) {
                $errors[$path . '.code'][] = 'Transition codes must be unique.';

                continue;
            }

            $seen[$code] = true;
            $transitions[] = new DraftTransitionInput(
                code: $code,
                name: $name,
                fromCode: $from,
                toCode: $to,
                permissionCode: $this->optionalNullableString($raw, 'permission_code'),
                isPrimary: $this->optionalBool($raw, 'is_primary'),
                position: $this->optionalInt($raw, 'position', $index),
                rules: $this->parseRules($raw, $path, $errors),
            );
        }

        return $transitions;
    }

    /**
     * @param array<string, mixed>        $transition
     * @param array<string, list<string>> $errors
     *
     * @return list<DraftRuleInput>
     */
    private function parseRules(array $transition, string $parentPath, array &$errors): array
    {
        $rawRules = $this->listField($transition, 'rules', $errors, $parentPath);
        $rules = [];

        foreach ($rawRules as $index => $raw) {
            $path = sprintf('%s.rules[%d]', $parentPath, $index);

            if (!is_array($raw)) {
                $errors[$path][] = 'Each rule must be an object.';

                continue;
            }

            /** @var array<string, mixed> $raw */
            $type = $this->requiredRuleType($raw, $path, $errors);
            $key = $this->requiredPattern($raw, 'key', self::RULE_KEY_PATTERN, $path, $errors);
            $configuration = $this->optionalObject($raw, 'configuration', $path, $errors);

            if ($type === null || $key === null) {
                continue;
            }

            $rules[] = new DraftRuleInput(
                ruleType: $type,
                ruleKey: $key,
                configuration: $configuration,
                position: $this->optionalInt($raw, 'position', $index),
            );
        }

        return $rules;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     */
    private function requiredString(
        array $source,
        string $key,
        array &$errors,
        string $path = '',
    ): ?string {
        $field = $path === '' ? $key : $path . '.' . $key;
        $value = $source[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            $errors[$field][] = sprintf('The field "%s" is required.', $key);

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     */
    private function requiredPattern(
        array $source,
        string $key,
        string $pattern,
        string $path,
        array &$errors,
    ): ?string {
        $field = $path . '.' . $key;
        $value = $source[$key] ?? null;

        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            $errors[$field][] = sprintf('The field "%s" has an invalid format.', $key);

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, true>         $declaredCodes
     * @param array<string, list<string>> $errors
     */
    private function requiredMember(
        array $source,
        string $key,
        array $declaredCodes,
        string $path,
        array &$errors,
    ): ?string {
        $field = $path . '.' . $key;
        $value = $source[$key] ?? null;

        if (!is_string($value) || !isset($declaredCodes[$value])) {
            $errors[$field][] = sprintf('The field "%s" must reference a declared status.', $key);

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     */
    private function requiredCategory(array $source, string $path, array &$errors): ?StatusCategory
    {
        $value = $source['category'] ?? null;
        $category = is_string($value) ? StatusCategory::tryFrom($value) : null;

        if ($category === null) {
            $errors[$path . '.category'][] = 'The status category is required and must be known.';
        }

        return $category;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     */
    private function requiredRuleType(
        array $source,
        string $path,
        array &$errors,
    ): ?TransitionRuleType {
        $value = $source['type'] ?? null;
        $type = is_string($value) ? TransitionRuleType::tryFrom($value) : null;

        if ($type === null) {
            $errors[$path . '.type'][] = 'The rule type is required and must be known.';
        }

        return $type;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     */
    private function requiredInt(array $source, string $key, array &$errors): ?int
    {
        $value = $source[$key] ?? null;

        if (!is_int($value)) {
            $errors[$key][] = sprintf('The field "%s" must be an integer.', $key);

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     *
     * @return list<mixed>
     */
    private function listField(array $source, string $key, array &$errors, string $path = ''): array
    {
        $field = $path === '' ? $key : $path . '.' . $key;
        $value = $source[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            $errors[$field][] = sprintf('The field "%s" must be an array.', $key);

            return [];
        }

        return $value;
    }

    /**
     * @param array<string, mixed>        $source
     * @param array<string, list<string>> $errors
     *
     * @return array<string, mixed>
     */
    private function optionalObject(array $source, string $key, string $path, array &$errors): array
    {
        $value = $source[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            $errors[$path . '.' . $key][] = sprintf('The field "%s" must be an object.', $key);

            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function optionalString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function optionalNullableString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function optionalBool(array $source, string $key): bool
    {
        return ($source[$key] ?? null) === true;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function optionalInt(array $source, string $key, int $default): int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : $default;
    }
}
