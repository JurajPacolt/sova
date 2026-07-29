<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use JsonException;
use Sova\Issues\Application\History\HistoryEntry;
use Sova\Issues\Application\History\HistoryRepository;

final readonly class DoctrineHistoryRepository implements HistoryRepository
{
    public function __construct(private Connection $connection) {}

    public function listForIssue(string $tenantId, string $issueId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT history.id,
                       history.issue_id,
                       history.issue_version,
                       history.event_type,
                       history.actor_user_id,
                       actor.display_name AS actor_display_name,
                       from_status.code AS from_status_code,
                       from_status.name AS from_status_name,
                       to_status.code AS to_status_code,
                       to_status.name AS to_status_name,
                       history.metadata,
                       history.created_at
                FROM issue_history history
                LEFT JOIN users actor
                    ON actor.id = history.actor_user_id
                LEFT JOIN project_statuses from_status
                    ON from_status.tenant_id = history.tenant_id
                    AND from_status.project_id = history.project_id
                    AND from_status.id = history.from_status_id
                LEFT JOIN project_statuses to_status
                    ON to_status.tenant_id = history.tenant_id
                    AND to_status.project_id = history.project_id
                    AND to_status.id = history.to_status_id
                WHERE history.tenant_id = :tenant_id
                    AND history.issue_id = :issue_id
                ORDER BY history.created_at DESC, history.id DESC
                LIMIT :entry_limit
                SQL,
            [
                'tenant_id' => $tenantId,
                'issue_id' => $issueId,
                'entry_limit' => $limit,
            ],
            ['entry_limit' => ParameterType::INTEGER],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): HistoryEntry
    {
        return new HistoryEntry(
            $this->string($row, 'id'),
            $this->string($row, 'issue_id'),
            (int) $this->string($row, 'issue_version'),
            $this->string($row, 'event_type'),
            $this->nullableString($row, 'actor_user_id'),
            $this->nullableString($row, 'actor_display_name'),
            $this->nullableString($row, 'from_status_code'),
            $this->nullableString($row, 'from_status_name'),
            $this->nullableString($row, 'to_status_code'),
            $this->nullableString($row, 'to_status_name'),
            $this->metadata($this->nullableString($row, 'metadata')),
            $this->moment($this->string($row, 'created_at')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $metadata = [];

        foreach ($decoded as $key => $item) {
            $metadata[(string) $key] = $item;
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    private function moment(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }
}
