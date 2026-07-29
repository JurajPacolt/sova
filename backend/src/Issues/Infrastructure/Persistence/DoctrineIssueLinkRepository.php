<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Exception;
use Sova\Issues\Application\Link\IssueLink;
use Sova\Issues\Application\Link\IssueLinkRecord;
use Sova\Issues\Application\Link\IssueLinkRepository;
use Sova\Issues\Domain\Link\IssueLinkType;

final readonly class DoctrineIssueLinkRepository implements IssueLinkRepository
{
    public function __construct(private Connection $connection) {}

    public function listForIssue(
        string $tenantId,
        string $issueId,
        array $visibleProjectIds,
    ): array {
        if ($visibleProjectIds === []) {
            return [];
        }

        // One row per link, read from whichever end the caller asked about. The
        // `outward` flag tells the caller which label applies; the other end is
        // filtered to projects they may see, so an unreachable issue is absent
        // rather than named.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT link.id,
                       link.link_type,
                       link.created_at,
                       (link.source_issue_id = :issue_id) AS outward,
                       other.id AS other_issue_id,
                       other.issue_key AS other_issue_key,
                       other.title AS other_issue_title,
                       other.project_id AS other_project_id,
                       other_status.code AS other_status_code,
                       other_status.category AS other_status_category
                FROM issue_links link
                INNER JOIN issues other
                    ON other.tenant_id = link.tenant_id
                    AND other.id = CASE
                        WHEN link.source_issue_id = :issue_id
                            THEN link.target_issue_id
                        ELSE link.source_issue_id
                    END
                INNER JOIN project_statuses other_status
                    ON other_status.tenant_id = other.tenant_id
                    AND other_status.project_id = other.project_id
                    AND other_status.id = other.status_id
                WHERE link.tenant_id = :tenant_id
                    AND (
                        link.source_issue_id = :issue_id
                        OR link.target_issue_id = :issue_id
                    )
                    AND other.project_id IN (:project_ids)
                ORDER BY link.created_at ASC, link.id ASC
                SQL,
            [
                'tenant_id' => $tenantId,
                'issue_id' => $issueId,
                'project_ids' => $visibleProjectIds,
            ],
            ['project_ids' => ArrayParameterType::STRING],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $tenantId, string $linkId): ?IssueLinkRecord
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT link.id,
                       link.link_type,
                       link.source_issue_id,
                       source_issue.project_id AS source_project_id,
                       link.target_issue_id,
                       target_issue.project_id AS target_project_id
                FROM issue_links link
                INNER JOIN issues source_issue
                    ON source_issue.tenant_id = link.tenant_id
                    AND source_issue.id = link.source_issue_id
                INNER JOIN issues target_issue
                    ON target_issue.tenant_id = link.tenant_id
                    AND target_issue.id = link.target_issue_id
                WHERE link.tenant_id = :tenant_id
                    AND link.id = :link_id
                SQL,
            ['tenant_id' => $tenantId, 'link_id' => $linkId],
        );

        if ($row === false) {
            return null;
        }

        $type = IssueLinkType::tryFrom($this->string($row, 'link_type'));

        if ($type === null) {
            return null;
        }

        return new IssueLinkRecord(
            $this->string($row, 'id'),
            $this->string($row, 'source_issue_id'),
            $this->string($row, 'source_project_id'),
            $this->string($row, 'target_issue_id'),
            $this->string($row, 'target_project_id'),
            $type,
        );
    }

    public function pairExists(
        string $tenantId,
        string $sourceIssueId,
        string $targetIssueId,
        IssueLinkType $type,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM issue_links link
                    WHERE link.tenant_id = :tenant_id
                        AND link.link_type = :link_type
                        AND (
                            (link.source_issue_id = :source_id
                                AND link.target_issue_id = :target_id)
                            OR (link.source_issue_id = :target_id
                                AND link.target_issue_id = :source_id)
                        )
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'source_id' => $sourceIssueId,
                'target_id' => $targetIssueId,
                'link_type' => $type->value,
            ],
        );

        return in_array($value, [true, 1, '1', 't'], true);
    }

    public function create(
        string $tenantId,
        string $linkId,
        string $sourceIssueId,
        string $targetIssueId,
        IssueLinkType $type,
        string $createdByUserId,
    ): void {
        $this->connection->insert('issue_links', [
            'id' => $linkId,
            'tenant_id' => $tenantId,
            'source_issue_id' => $sourceIssueId,
            'target_issue_id' => $targetIssueId,
            'link_type' => $type->value,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function delete(string $tenantId, string $linkId): void
    {
        $this->connection->delete('issue_links', [
            'tenant_id' => $tenantId,
            'id' => $linkId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): IssueLink
    {
        $type = IssueLinkType::tryFrom($this->string($row, 'link_type'))
            ?? IssueLinkType::RelatesTo;
        $outward = in_array($row['outward'] ?? null, [true, 1, '1', 't'], true);

        return new IssueLink(
            $this->string($row, 'id'),
            $type,
            $outward ? $type->value : $type->inverseLabel(),
            $outward,
            $this->string($row, 'other_issue_id'),
            $this->string($row, 'other_issue_key'),
            $this->string($row, 'other_issue_title'),
            $this->string($row, 'other_project_id'),
            $this->string($row, 'other_status_code'),
            $this->string($row, 'other_status_category'),
            $this->moment($this->string($row, 'created_at')),
        );
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

    private function moment(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }
}
