<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

/**
 * The fixed register of supported transition rules from
 * WORKFLOW-A-TYPY-ULOH.md §6.3. A rule key maps to exactly one type and a
 * configuration schema; an unknown key or a malformed configuration is
 * rejected so a draft can never reference an unimplemented rule (fail-closed).
 */
final class TransitionRuleCatalog
{
    /**
     * @return array<string, TransitionRuleType>
     */
    public static function all(): array
    {
        return [
            'permission' => TransitionRuleType::Condition,
            'assignee_or_manager' => TransitionRuleType::Condition,
            'required_field' => TransitionRuleType::Validator,
            'resolution_required' => TransitionRuleType::Validator,
            'set_resolution' => TransitionRuleType::Action,
            'clear_resolution' => TransitionRuleType::Action,
            'set_resolved_at' => TransitionRuleType::Action,
            'clear_resolved_at' => TransitionRuleType::Action,
        ];
    }

    public static function typeFor(string $key): ?TransitionRuleType
    {
        return self::all()[$key] ?? null;
    }

    public static function supports(TransitionRuleType $type, string $key): bool
    {
        return self::typeFor($key) === $type;
    }

    /**
     * Validates the structured configuration of a rule against its key's
     * schema.
     *
     * @param array<string, mixed> $configuration
     *
     * @return list<string> human-readable problems, empty when the config is valid
     */
    public static function validateConfiguration(string $key, array $configuration): array
    {
        return match ($key) {
            'permission' => self::requireString($configuration, 'permission'),
            'required_field' => self::requireString($configuration, 'field'),
            'set_resolution' => self::requireString($configuration, 'resolution'),
            'assignee_or_manager',
            'resolution_required',
            'clear_resolution',
            'set_resolved_at',
            'clear_resolved_at' => self::requireEmpty($configuration),
            default => [sprintf('Unknown transition rule "%s".', $key)],
        };
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return list<string>
     */
    private static function requireString(array $configuration, string $field): array
    {
        $value = $configuration[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return [sprintf('Rule configuration requires a non-empty "%s".', $field)];
        }

        return self::rejectExtraKeys($configuration, [$field]);
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return list<string>
     */
    private static function requireEmpty(array $configuration): array
    {
        return self::rejectExtraKeys($configuration, []);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param list<string>         $allowed
     *
     * @return list<string>
     */
    private static function rejectExtraKeys(array $configuration, array $allowed): array
    {
        $extra = array_diff(array_keys($configuration), $allowed);

        if ($extra !== []) {
            return [sprintf(
                'Rule configuration has unsupported keys: %s.',
                implode(', ', array_map(strval(...), $extra)),
            )];
        }

        return [];
    }
}
