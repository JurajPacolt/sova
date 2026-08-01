<?php

declare(strict_types=1);

namespace Sova\Notifications\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Sova\Notifications\Application\ChannelPreference;
use Sova\Notifications\Application\PreferenceRepository;
use Sova\Notifications\Domain\NotificationKind;

final readonly class DoctrinePreferenceRepository implements PreferenceRepository
{
    public function __construct(private Connection $connection) {}

    public function forMember(string $tenantId, string $membershipId): array
    {
        return $this->forMembers($tenantId, [$membershipId])[$membershipId]
            ?? $this->defaults();
    }

    public function forMembers(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $effective = [];

        foreach ($membershipIds as $membershipId) {
            $effective[$membershipId] = $this->defaults();
        }

        foreach ($this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT membership_id, kind, in_app, email
                FROM notification_preferences
                WHERE tenant_id = :tenant_id
                    AND membership_id IN (:membership_ids)
                SQL,
            ['tenant_id' => $tenantId, 'membership_ids' => array_values($membershipIds)],
            ['membership_ids' => ArrayParameterType::STRING],
        ) as $row) {
            $membershipId = $row['membership_id'] ?? null;
            $kind = NotificationKind::tryFrom(
                is_string($row['kind'] ?? null) ? $row['kind'] : '',
            );

            // A stored row for a kind the catalog no longer knows is ignored
            // rather than resurrected.
            if (!is_string($membershipId) || $kind === null) {
                continue;
            }

            $effective[$membershipId][$kind->value] = new ChannelPreference(
                $kind,
                $this->flag($row['in_app'] ?? null),
                $this->flag($row['email'] ?? null),
            );
        }

        return $effective;
    }

    public function replace(
        string $tenantId,
        string $membershipId,
        array $preferences,
    ): void {
        $this->connection->delete('notification_preferences', [
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
        ]);

        foreach ($preferences as $preference) {
            $this->connection->insert(
                'notification_preferences',
                [
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                    'kind' => $preference->kind->value,
                    'in_app' => $preference->inApp,
                    'email' => $preference->email,
                ],
                [
                    'in_app' => ParameterType::BOOLEAN,
                    'email' => ParameterType::BOOLEAN,
                ],
            );
        }
    }

    /**
     * @return array<string, ChannelPreference>
     */
    private function defaults(): array
    {
        $defaults = [];

        foreach (NotificationKind::cases() as $kind) {
            $defaults[$kind->value] = ChannelPreference::default($kind);
        }

        return $defaults;
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
