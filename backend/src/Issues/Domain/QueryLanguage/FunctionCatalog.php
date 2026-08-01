<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * The functions SovaQL v1 recognises: identity/group references and relative
 * time anchors. Time offsets carry units `m` minute, `h` hour, `d` day, `w`
 * week and `M` calendar month, evaluated per run in the user's stored time
 * zone (spec §4.6–4.7).
 */
final class FunctionCatalog
{
    /** @var array<string, FunctionDefinition> */
    private array $functions;

    public function __construct()
    {
        $text = FunctionArgumentKind::Text;
        $offset = FunctionArgumentKind::Offset;

        $this->functions = [
            'currentuser' => new FunctionDefinition('currentUser', FunctionReturnType::User, 0, 0, $text),
            'user' => new FunctionDefinition('user', FunctionReturnType::User, 1, 1, $text),
            'group' => new FunctionDefinition('group', FunctionReturnType::Workgroup, 1, 1, $text),
            'membersof' => new FunctionDefinition('membersOf', FunctionReturnType::UserSet, 1, 1, $text),
            'now' => new FunctionDefinition('now', FunctionReturnType::DateTime, 0, 0, $offset),
            'startofday' => new FunctionDefinition('startOfDay', FunctionReturnType::DateTime, 0, 1, $offset),
            'endofday' => new FunctionDefinition('endOfDay', FunctionReturnType::DateTime, 0, 1, $offset),
            'startofweek' => new FunctionDefinition('startOfWeek', FunctionReturnType::DateTime, 0, 1, $offset),
            'endofweek' => new FunctionDefinition('endOfWeek', FunctionReturnType::DateTime, 0, 1, $offset),
            'startofmonth' => new FunctionDefinition('startOfMonth', FunctionReturnType::DateTime, 0, 1, $offset),
            'endofmonth' => new FunctionDefinition('endOfMonth', FunctionReturnType::DateTime, 0, 1, $offset),
        ];
    }

    public function definition(string $name): ?FunctionDefinition
    {
        return $this->functions[strtolower($name)] ?? null;
    }

    public static function isValidOffset(string $value): bool
    {
        return preg_match('/^[+-]?[0-9]+[mhdwM]$/', $value) === 1;
    }
}
