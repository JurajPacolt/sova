<?php

declare(strict_types=1);

namespace Sova\Notifications\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use JsonException;
use Sova\Notifications\Application\Notification;
use Sova\Notifications\Application\NotificationRepository;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Every statement is keyed by tenant *and* recipient membership, so one member
 * can never read or acknowledge another's inbox even with a guessed identifier.
 */
final readonly class DoctrineNotificationRepository implements NotificationRepository
{
    public function __construct(private Connection $connection) {}

    public function listForMember(
        string $tenantId,
        string $membershipId,
        bool $unreadOnly,
        int $limit,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT notification.id,
                           notification.kind,
                           notification.project_id,
                           notification.issue_id,
                           notification.actor_user_id,
                           actor.display_name AS actor_display_name,
                           notification.payload,
                           notification.read_at,
                           notification.created_at
                    FROM notifications notification
                    LEFT JOIN users actor
                        ON actor.id = notification.actor_user_id
                    WHERE notification.tenant_id = :tenant_id
                        AND notification.recipient_membership_id = :membership_id
                        %s
                    ORDER BY notification.created_at DESC, notification.id DESC
                    LIMIT :entry_limit
                    SQL,
                $unreadOnly ? 'AND notification.read_at IS NULL' : '',
            ),
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'entry_limit' => $limit,
            ],
            ['entry_limit' => ParameterType::INTEGER],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function unreadCount(string $tenantId, string $membershipId): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                FROM notifications
                WHERE tenant_id = :tenant_id
                    AND recipient_membership_id = :membership_id
                    AND read_at IS NULL
                SQL,
            ['tenant_id' => $tenantId, 'membership_id' => $membershipId],
        );

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    public function markRead(
        string $tenantId,
        string $membershipId,
        array $notificationIds,
    ): int {
        $parameters = [
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
        ];
        $types = [];
        $filter = '';

        if ($notificationIds !== []) {
            $filter = 'AND id IN (:notification_ids)';
            $parameters['notification_ids'] = $notificationIds;
            $types['notification_ids'] = ArrayParameterType::STRING;
        }

        return (int) $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE notifications
                    SET read_at = CURRENT_TIMESTAMP
                    WHERE tenant_id = :tenant_id
                        AND recipient_membership_id = :membership_id
                        AND read_at IS NULL
                        %s
                    SQL,
                $filter,
            ),
            $parameters,
            $types,
        );
    }

    public function deliver(
        string $tenantId,
        string $eventId,
        string $recipientMembershipId,
        string $kind,
        ?string $projectId,
        ?string $issueId,
        ?string $actorUserId,
        array $payload,
    ): void {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encoded = '{}';
        }

        // `DO NOTHING` on the (event, recipient, kind) key is what makes the
        // handler safe to replay: a redelivered event finds its row already
        // there and changes nothing.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO notifications (
                    id, tenant_id, recipient_membership_id, event_id, kind,
                    project_id, issue_id, actor_user_id, payload
                )
                VALUES (
                    :id, :tenant_id, :membership_id, :event_id, :kind,
                    :project_id, :issue_id, :actor_user_id, :payload::jsonb
                )
                ON CONFLICT (event_id, recipient_membership_id, kind) DO NOTHING
                SQL,
            [
                'id' => (string) UuidV7::generate(),
                'tenant_id' => $tenantId,
                'membership_id' => $recipientMembershipId,
                'event_id' => $eventId,
                'kind' => $kind,
                'project_id' => $projectId,
                'issue_id' => $issueId,
                'actor_user_id' => $actorUserId,
                'payload' => $encoded,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Notification
    {
        return new Notification(
            $this->string($row, 'id'),
            $this->string($row, 'kind'),
            $this->nullableString($row, 'project_id'),
            $this->nullableString($row, 'issue_id'),
            $this->nullableString($row, 'actor_user_id'),
            $this->nullableString($row, 'actor_display_name'),
            $this->payload($this->nullableString($row, 'payload')),
            $this->moment($this->nullableString($row, 'read_at')),
            $this->moment($this->string($row, 'created_at')) ?? new DateTimeImmutable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $value): array
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

        $payload = [];

        foreach ($decoded as $key => $item) {
            $payload[(string) $key] = $item;
        }

        return $payload;
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
