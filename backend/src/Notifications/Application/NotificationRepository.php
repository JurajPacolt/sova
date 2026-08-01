<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

interface NotificationRepository
{
    /**
     * @return list<Notification> newest first
     */
    public function listForMember(
        string $tenantId,
        string $membershipId,
        bool $unreadOnly,
        int $limit,
    ): array;

    public function unreadCount(string $tenantId, string $membershipId): int;

    /**
     * Marks the member's notifications as read. An empty list means "all of
     * them"; identifiers that do not belong to the member are ignored rather
     * than reported, so the endpoint cannot be used to probe.
     *
     * @param list<string> $notificationIds
     *
     * @return int notifications actually changed
     */
    public function markRead(
        string $tenantId,
        string $membershipId,
        array $notificationIds,
    ): int;

    /**
     * Records a delivery. Keyed on the outbox event, so replaying that event
     * leaves the inbox unchanged instead of duplicating the entry.
     *
     * @param array<string, mixed> $payload
     */
    public function deliver(
        string $tenantId,
        string $eventId,
        string $recipientMembershipId,
        string $kind,
        ?string $projectId,
        ?string $issueId,
        ?string $actorUserId,
        array $payload,
    ): void;
}
