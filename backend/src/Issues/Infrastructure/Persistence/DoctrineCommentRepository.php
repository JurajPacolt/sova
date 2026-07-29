<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Exception;
use Sova\Issues\Application\Comment\CommentDetails;
use Sova\Issues\Application\Comment\CommentMention;
use Sova\Issues\Application\Comment\CommentRecord;
use Sova\Issues\Application\Comment\CommentRepository;

/**
 * Every statement is keyed by tenant, so a comment identifier from another
 * tenant reads as missing instead of being acted on.
 */
final readonly class DoctrineCommentRepository implements CommentRepository
{
    public function __construct(private Connection $connection) {}

    public function listForIssue(string $tenantId, string $issueId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT comment.id,
                       comment.issue_id,
                       comment.author_membership_id,
                       author_user.display_name AS author_display_name,
                       comment.body,
                       comment.version,
                       comment.edited_at,
                       comment.deleted_at,
                       comment.created_at,
                       comment.updated_at
                FROM issue_comments comment
                INNER JOIN tenant_memberships author
                    ON author.tenant_id = comment.tenant_id
                    AND author.id = comment.author_membership_id
                INNER JOIN users author_user
                    ON author_user.id = author.user_id
                WHERE comment.tenant_id = :tenant_id
                    AND comment.issue_id = :issue_id
                ORDER BY comment.created_at ASC, comment.id ASC
                SQL,
            ['tenant_id' => $tenantId, 'issue_id' => $issueId],
        );

        if ($rows === []) {
            return [];
        }

        $mentions = $this->mentionsFor(
            $tenantId,
            array_values(array_filter(array_map(
                static fn(array $row): ?string => is_string($row['id'] ?? null)
                    ? $row['id']
                    : null,
                $rows,
            ))),
        );

        $comments = [];

        foreach ($rows as $row) {
            $id = $this->string($row, 'id');
            $deleted = $this->nullableString($row, 'deleted_at') !== null;

            $comments[] = new CommentDetails(
                $id,
                $this->string($row, 'issue_id'),
                $this->string($row, 'author_membership_id'),
                $this->nullableString($row, 'author_display_name'),
                // A removed comment keeps its place in the discussion but never
                // returns its text or the people it addressed.
                $deleted ? null : $this->string($row, 'body'),
                (int) $this->string($row, 'version'),
                $deleted,
                $deleted ? [] : ($mentions[$id] ?? []),
                $this->moment($this->nullableString($row, 'edited_at')),
                $this->moment($this->string($row, 'created_at')) ?? new DateTimeImmutable(),
                $this->moment($this->string($row, 'updated_at')) ?? new DateTimeImmutable(),
            );
        }

        return $comments;
    }

    public function find(string $tenantId, string $commentId): ?CommentRecord
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT comment.id,
                       comment.tenant_id,
                       comment.project_id,
                       comment.issue_id,
                       comment.author_membership_id,
                       author.user_id AS author_user_id,
                       comment.version,
                       comment.deleted_at,
                       comment.created_at
                FROM issue_comments comment
                INNER JOIN tenant_memberships author
                    ON author.tenant_id = comment.tenant_id
                    AND author.id = comment.author_membership_id
                WHERE comment.tenant_id = :tenant_id
                    AND comment.id = :comment_id
                SQL,
            ['tenant_id' => $tenantId, 'comment_id' => $commentId],
        );

        if ($row === false) {
            return null;
        }

        return new CommentRecord(
            $this->string($row, 'id'),
            $this->string($row, 'tenant_id'),
            $this->string($row, 'project_id'),
            $this->string($row, 'issue_id'),
            $this->string($row, 'author_membership_id'),
            $this->string($row, 'author_user_id'),
            (int) $this->string($row, 'version'),
            $this->nullableString($row, 'deleted_at') !== null,
            $this->moment($this->string($row, 'created_at')) ?? new DateTimeImmutable(),
        );
    }

    public function activeMembershipUsers(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $users = [];

        foreach ($this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT membership.id, membership.user_id
                FROM tenant_memberships membership
                INNER JOIN users user_account
                    ON user_account.id = membership.user_id
                WHERE membership.tenant_id = :tenant_id
                    AND membership.id IN (:membership_ids)
                    AND membership.status = 'ACTIVE'
                    AND user_account.status = 'ACTIVE'
                SQL,
            ['tenant_id' => $tenantId, 'membership_ids' => $membershipIds],
            ['membership_ids' => ArrayParameterType::STRING],
        ) as $row) {
            $users[strtolower($this->string($row, 'id'))] = $this->string($row, 'user_id');
        }

        return $users;
    }

    public function create(
        string $tenantId,
        string $projectId,
        string $issueId,
        string $commentId,
        string $authorMembershipId,
        string $body,
        array $mentionedMembershipIds,
    ): void {
        $this->connection->insert('issue_comments', [
            'id' => $commentId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'issue_id' => $issueId,
            'author_membership_id' => $authorMembershipId,
            'body' => $body,
        ]);

        $this->replaceMentions($tenantId, $commentId, $mentionedMembershipIds);
    }

    public function update(
        string $tenantId,
        string $commentId,
        string $body,
        array $mentionedMembershipIds,
    ): int {
        $version = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE issue_comments
                SET body = :body,
                    version = version + 1,
                    edited_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :comment_id
                    AND deleted_at IS NULL
                RETURNING version
                SQL,
            ['tenant_id' => $tenantId, 'comment_id' => $commentId, 'body' => $body],
        );

        $this->replaceMentions($tenantId, $commentId, $mentionedMembershipIds);

        return is_int($version) ? $version : (int) (is_string($version) ? $version : 1);
    }

    public function softDelete(
        string $tenantId,
        string $commentId,
        string $deletedByUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE issue_comments
                SET body = '',
                    version = version + 1,
                    deleted_at = CURRENT_TIMESTAMP,
                    deleted_by_user_id = :deleted_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :comment_id
                    AND deleted_at IS NULL
                SQL,
            [
                'tenant_id' => $tenantId,
                'comment_id' => $commentId,
                'deleted_by' => $deletedByUserId,
            ],
        );

        // The text is gone, so the people it addressed go with it.
        $this->connection->delete('issue_comment_mentions', [
            'tenant_id' => $tenantId,
            'comment_id' => $commentId,
        ]);
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    /**
     * @param list<string> $membershipIds
     */
    private function replaceMentions(
        string $tenantId,
        string $commentId,
        array $membershipIds,
    ): void {
        $this->connection->delete('issue_comment_mentions', [
            'tenant_id' => $tenantId,
            'comment_id' => $commentId,
        ]);

        foreach ($membershipIds as $membershipId) {
            $this->connection->insert('issue_comment_mentions', [
                'tenant_id' => $tenantId,
                'comment_id' => $commentId,
                'membership_id' => $membershipId,
            ]);
        }
    }

    /**
     * @param list<string> $commentIds
     *
     * @return array<string, list<CommentMention>>
     */
    private function mentionsFor(string $tenantId, array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $mentions = [];

        foreach ($this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT mention.comment_id,
                       mention.membership_id,
                       mentioned_user.display_name
                FROM issue_comment_mentions mention
                INNER JOIN tenant_memberships mentioned
                    ON mentioned.tenant_id = mention.tenant_id
                    AND mentioned.id = mention.membership_id
                INNER JOIN users mentioned_user
                    ON mentioned_user.id = mentioned.user_id
                WHERE mention.tenant_id = :tenant_id
                    AND mention.comment_id IN (:comment_ids)
                ORDER BY mentioned_user.display_name ASC
                SQL,
            ['tenant_id' => $tenantId, 'comment_ids' => $commentIds],
            ['comment_ids' => ArrayParameterType::STRING],
        ) as $row) {
            $mentions[$this->string($row, 'comment_id')][] = new CommentMention(
                $this->string($row, 'membership_id'),
                $this->nullableString($row, 'display_name'),
            );
        }

        return $mentions;
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

    private function moment(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
