<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Comment;

interface CommentRepository
{
    /**
     * @return list<CommentDetails> oldest first, the reading order of a discussion
     */
    public function listForIssue(string $tenantId, string $issueId): array;

    public function find(string $tenantId, string $commentId): ?CommentRecord;

    /**
     * Resolves mentioned memberships to the user behind each one, keeping only
     * active memberships of this tenant. An identifier that is missing from the
     * result is either unknown or inactive; the caller must not tell the two
     * apart in its response.
     *
     * @param list<string> $membershipIds
     *
     * @return array<string, string> membership identifier to user identifier
     */
    public function activeMembershipUsers(string $tenantId, array $membershipIds): array;

    /**
     * @param list<string> $mentionedMembershipIds
     */
    public function create(
        string $tenantId,
        string $projectId,
        string $issueId,
        string $commentId,
        string $authorMembershipId,
        string $body,
        array $mentionedMembershipIds,
    ): void;

    /**
     * @param list<string> $mentionedMembershipIds
     */
    public function update(
        string $tenantId,
        string $commentId,
        string $body,
        array $mentionedMembershipIds,
    ): int;

    public function softDelete(
        string $tenantId,
        string $commentId,
        string $deletedByUserId,
    ): void;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
