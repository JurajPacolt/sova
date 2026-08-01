<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use Sova\Issues\Application\Watcher\Watcher;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Issues\Application\Watcher\WatchSource;

final readonly class DoctrineWatcherRepository implements WatcherRepository
{
    public function __construct(private Connection $connection) {}

    public function listForIssue(string $tenantId, string $issueId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT watcher.membership_id,
                       watcher.source,
                       watcher.created_at,
                       watching_user.display_name
                FROM issue_watchers watcher
                INNER JOIN tenant_memberships membership
                    ON membership.tenant_id = watcher.tenant_id
                    AND membership.id = watcher.membership_id
                INNER JOIN users watching_user
                    ON watching_user.id = membership.user_id
                WHERE watcher.tenant_id = :tenant_id
                    AND watcher.issue_id = :issue_id
                    AND watcher.watching
                    AND membership.status = 'ACTIVE'
                ORDER BY watching_user.display_name ASC, watcher.membership_id ASC
                SQL,
            ['tenant_id' => $tenantId, 'issue_id' => $issueId],
        );

        $watchers = [];

        foreach ($rows as $row) {
            $watchers[] = new Watcher(
                $this->string($row, 'membership_id'),
                $this->nullableString($row, 'display_name'),
                WatchSource::tryFrom($this->string($row, 'source'))
                    ?? WatchSource::Explicit,
                $this->moment($this->string($row, 'created_at')),
            );
        }

        return $watchers;
    }

    public function watchingMembershipIds(
        string $tenantId,
        string $issueId,
        ?string $excludeUserId = null,
    ): array {
        $identifiers = [];

        foreach ($this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT watcher.membership_id
                FROM issue_watchers watcher
                INNER JOIN tenant_memberships membership
                    ON membership.tenant_id = watcher.tenant_id
                    AND membership.id = watcher.membership_id
                INNER JOIN users watching_user
                    ON watching_user.id = membership.user_id
                WHERE watcher.tenant_id = :tenant_id
                    AND watcher.issue_id = :issue_id
                    AND watcher.watching
                    AND membership.status = 'ACTIVE'
                    AND watching_user.status = 'ACTIVE'
                    AND (:exclude_user_id::uuid IS NULL
                        OR membership.user_id <> :exclude_user_id::uuid)
                ORDER BY watcher.membership_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'issue_id' => $issueId,
                'exclude_user_id' => $excludeUserId,
            ],
        ) as $value) {
            if (is_string($value)) {
                $identifiers[] = $value;
            }
        }

        return $identifiers;
    }

    public function isWatching(
        string $tenantId,
        string $issueId,
        string $membershipId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT watching
                FROM issue_watchers
                WHERE tenant_id = :tenant_id
                    AND issue_id = :issue_id
                    AND membership_id = :membership_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'issue_id' => $issueId,
                'membership_id' => $membershipId,
            ],
        );

        return in_array($value, [true, 1, '1', 't'], true);
    }

    public function setWatching(
        string $tenantId,
        string $projectId,
        string $issueId,
        string $membershipId,
        bool $watching,
    ): void {
        // An explicit decision always wins, including the decision to stop —
        // which is stored rather than deleted so the automatic rules below
        // cannot resubscribe the member behind their back.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO issue_watchers (
                    tenant_id, project_id, issue_id, membership_id,
                    watching, source, created_at, updated_at
                )
                VALUES (
                    :tenant_id, :project_id, :issue_id, :membership_id,
                    :watching, 'EXPLICIT', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (issue_id, membership_id) DO UPDATE SET
                    watching = EXCLUDED.watching,
                    source = 'EXPLICIT',
                    updated_at = CURRENT_TIMESTAMP
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_id' => $issueId,
                'membership_id' => $membershipId,
                'watching' => $watching,
            ],
            ['watching' => ParameterType::BOOLEAN],
        );
    }

    public function watchAutomatically(
        string $tenantId,
        string $projectId,
        string $issueId,
        string $membershipId,
        WatchSource $source,
    ): void {
        // `DO NOTHING` is the whole point: a stored decision, in either
        // direction, is never overwritten by an automatic rule.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO issue_watchers (
                    tenant_id, project_id, issue_id, membership_id,
                    watching, source, created_at, updated_at
                )
                VALUES (
                    :tenant_id, :project_id, :issue_id, :membership_id,
                    TRUE, :source, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON CONFLICT (issue_id, membership_id) DO NOTHING
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_id' => $issueId,
                'membership_id' => $membershipId,
                'source' => $source->value,
            ],
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
