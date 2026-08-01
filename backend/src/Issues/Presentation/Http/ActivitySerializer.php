<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http;

use Sova\Issues\Application\Comment\CommentDetails;
use Sova\Issues\Application\Comment\CommentMention;
use Sova\Issues\Application\History\HistoryEntry;

final readonly class ActivitySerializer
{
    /**
     * The body is the stored CommonMark **source**. The API never returns
     * rendered HTML, so the client is responsible for rendering it through an
     * allowlist renderer with raw HTML disabled.
     *
     * @return array<string, mixed>
     */
    public function serializeComment(CommentDetails $comment): array
    {
        return [
            'id' => $comment->id,
            'issue_id' => $comment->issueId,
            'author' => [
                'membership_id' => $comment->authorMembershipId,
                'display_name' => $comment->authorDisplayName,
            ],
            'body' => $comment->body,
            'version' => $comment->version,
            'deleted' => $comment->deleted,
            'mentions' => array_map(
                static fn(CommentMention $mention): array => [
                    'membership_id' => $mention->membershipId,
                    'display_name' => $mention->displayName,
                ],
                $comment->mentions,
            ),
            'edited_at' => $comment->editedAt?->format(DATE_ATOM),
            'created_at' => $comment->createdAt->format(DATE_ATOM),
            'updated_at' => $comment->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeHistoryEntry(HistoryEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'issue_id' => $entry->issueId,
            'issue_version' => $entry->issueVersion,
            'event_type' => $entry->eventType,
            'actor' => $entry->actorUserId === null ? null : [
                'user_id' => $entry->actorUserId,
                'display_name' => $entry->actorDisplayName,
            ],
            'from_status' => $entry->fromStatusCode === null ? null : [
                'code' => $entry->fromStatusCode,
                'name' => $entry->fromStatusName,
            ],
            'to_status' => $entry->toStatusCode === null ? null : [
                'code' => $entry->toStatusCode,
                'name' => $entry->toStatusName,
            ],
            'metadata' => $entry->metadata,
            'created_at' => $entry->createdAt->format(DATE_ATOM),
        ];
    }
}
