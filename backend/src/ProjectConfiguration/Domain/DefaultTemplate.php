<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

/**
 * The template every new project is seeded with. It is copied, never linked:
 * changing these constants later leaves existing projects untouched.
 */
final class DefaultTemplate
{
    public const WORKFLOW_NAME = 'Default workflow';
    public const INITIAL_STATUS_CODE = 'OPEN';

    /**
     * @return list<array{code: string, name: string, level: HierarchyLevel, position: int}>
     */
    public static function issueTypes(): array
    {
        return [
            ['code' => 'EPIC', 'name' => 'Epic', 'level' => HierarchyLevel::Epic, 'position' => 10],
            ['code' => 'STORY', 'name' => 'User story', 'level' => HierarchyLevel::Standard, 'position' => 20],
            ['code' => 'TASK', 'name' => 'Task', 'level' => HierarchyLevel::Standard, 'position' => 30],
            ['code' => 'BUG', 'name' => 'Bug', 'level' => HierarchyLevel::Standard, 'position' => 40],
            ['code' => 'SUBTASK', 'name' => 'Sub-task', 'level' => HierarchyLevel::Subtask, 'position' => 50],
        ];
    }

    /**
     * @return list<array{code: string, name: string, category: StatusCategory, position: int}>
     */
    public static function statuses(): array
    {
        return [
            ['code' => 'OPEN', 'name' => 'Open', 'category' => StatusCategory::ToDo, 'position' => 10],
            ['code' => 'IN_PROGRESS', 'name' => 'In progress', 'category' => StatusCategory::InProgress, 'position' => 20],
            ['code' => 'RESOLVED', 'name' => 'Resolved', 'category' => StatusCategory::Done, 'position' => 30],
            ['code' => 'CLOSED', 'name' => 'Closed', 'category' => StatusCategory::Done, 'position' => 40],
        ];
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     from: string,
     *     to: string,
     *     primary: bool,
     *     position: int,
     * }>
     */
    public static function transitions(): array
    {
        return [
            ['code' => 'START', 'name' => 'Start progress', 'from' => 'OPEN', 'to' => 'IN_PROGRESS', 'primary' => true, 'position' => 10],
            ['code' => 'STOP', 'name' => 'Stop progress', 'from' => 'IN_PROGRESS', 'to' => 'OPEN', 'primary' => false, 'position' => 20],
            ['code' => 'RESOLVE', 'name' => 'Resolve', 'from' => 'IN_PROGRESS', 'to' => 'RESOLVED', 'primary' => true, 'position' => 30],
            ['code' => 'CLOSE', 'name' => 'Close', 'from' => 'RESOLVED', 'to' => 'CLOSED', 'primary' => true, 'position' => 40],
            ['code' => 'REOPEN', 'name' => 'Reopen', 'from' => 'RESOLVED', 'to' => 'OPEN', 'primary' => false, 'position' => 50],
            ['code' => 'REOPEN_CLOSED', 'name' => 'Reopen', 'from' => 'CLOSED', 'to' => 'OPEN', 'primary' => false, 'position' => 60],
        ];
    }
}
