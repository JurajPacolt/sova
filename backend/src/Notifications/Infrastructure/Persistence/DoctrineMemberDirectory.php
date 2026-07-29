<?php

declare(strict_types=1);

namespace Sova\Notifications\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Sova\Notifications\Application\MemberContact;
use Sova\Notifications\Application\MemberDirectory;

/**
 * Reads the shared tenancy backbone directly, the same way the other modules
 * do for `tenant_memberships`. Only active memberships of active accounts are
 * returned, so a disabled member drops out of every audience without each
 * caller having to remember the check.
 */
final readonly class DoctrineMemberDirectory implements MemberDirectory
{
    public function __construct(private Connection $connection) {}

    public function usersFor(string $tenantId, array $membershipIds): array
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
            ['tenant_id' => $tenantId, 'membership_ids' => array_values($membershipIds)],
            ['membership_ids' => ArrayParameterType::STRING],
        ) as $row) {
            $membershipId = $row['id'] ?? null;
            $userId = $row['user_id'] ?? null;

            if (is_string($membershipId) && is_string($userId)) {
                $users[$membershipId] = $userId;
            }
        }

        return $users;
    }

    public function contactFor(string $tenantId, string $membershipId): ?MemberContact
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT user_account.email,
                       user_account.display_name,
                       user_account.preferred_locale
                FROM tenant_memberships membership
                INNER JOIN users user_account
                    ON user_account.id = membership.user_id
                WHERE membership.tenant_id = :tenant_id
                    AND membership.id = :membership_id
                    AND membership.status = 'ACTIVE'
                    AND user_account.status = 'ACTIVE'
                SQL,
            ['tenant_id' => $tenantId, 'membership_id' => $membershipId],
        );

        if ($row === false) {
            return null;
        }

        $email = $row['email'] ?? null;

        if (!is_string($email) || $email === '') {
            return null;
        }

        return new MemberContact(
            $email,
            is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
            is_string($row['preferred_locale'] ?? null) ? $row['preferred_locale'] : 'en',
        );
    }
}
