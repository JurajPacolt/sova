<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Identity\Application\System\SystemUserDetails;
use Sova\Identity\Application\System\SystemUserRepository;
use Sova\Identity\Domain\User\UserStatus;
use ValueError;

final readonly class DoctrineSystemUserRepository implements SystemUserRepository
{
    public function __construct(private Connection $connection) {}

    public function listAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->detailsSql() . "\nORDER BY LOWER(user_account.email), user_account.id",
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(
        string $userId,
        bool $forUpdate = false,
    ): ?SystemUserDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
            . "\nWHERE user_account.id = :user_id"
            . ($forUpdate ? "\nFOR UPDATE OF user_account" : ''),
            ['user_id' => $userId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function changeStatus(string $userId, UserStatus $status): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE users
                SET status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
                SQL,
            [
                'status' => $status->value,
                'user_id' => $userId,
            ],
        );
    }

    public function grantSuperadmin(
        string $userId,
        string $grantedByUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO user_system_roles (user_id, role_code, granted_by_user_id)
                VALUES (:user_id, 'SUPERADMIN', :granted_by_user_id)
                ON CONFLICT (user_id, role_code) DO NOTHING
                SQL,
            [
                'user_id' => $userId,
                'granted_by_user_id' => $grantedByUserId,
            ],
        );
    }

    public function revokeSuperadmin(string $userId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM user_system_roles
                WHERE user_id = :user_id
                    AND role_code = 'SUPERADMIN'
                SQL,
            ['user_id' => $userId],
        );
    }

    public function activeSuperadminCount(bool $forUpdate = false): int
    {
        $value = $this->connection->fetchOne(
            <<<SQL
                SELECT COUNT(*)
                FROM (
                    SELECT 1
                    FROM user_system_roles system_role
                    INNER JOIN users user_account
                        ON user_account.id = system_role.user_id
                    WHERE system_role.role_code = 'SUPERADMIN'
                        AND user_account.status = 'ACTIVE'
                    {$this->lockClause($forUpdate)}
                ) active_superadmin
                SQL,
        );

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(
            'The database returned an invalid active superadmin count.',
        );
    }

    private function lockClause(bool $forUpdate): string
    {
        return $forUpdate ? 'FOR UPDATE OF system_role' : '';
    }

    private function detailsSql(): string
    {
        return <<<'SQL'
            SELECT
                user_account.id,
                user_account.email,
                user_account.display_name,
                user_account.status,
                user_account.preferred_locale,
                user_account.email_verified_at,
                user_account.failed_login_count,
                user_account.locked_until,
                user_account.created_at,
                user_account.updated_at,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM user_system_roles system_role
                    WHERE system_role.user_id = user_account.id
                        AND system_role.role_code = 'SUPERADMIN'
                ) THEN 1 ELSE 0 END AS is_superadmin
            FROM users user_account
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SystemUserDetails
    {
        $statusValue = $this->stringValue($row, 'status');

        try {
            $status = UserStatus::from($statusValue);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf('Unknown user status "%s".', $statusValue),
                previous: $exception,
            );
        }

        return new SystemUserDetails(
            id: $this->stringValue($row, 'id'),
            email: $this->stringValue($row, 'email'),
            displayName: $this->stringValue($row, 'display_name'),
            status: $status,
            preferredLocale: $this->stringValue($row, 'preferred_locale'),
            emailVerifiedAt: $this->nullableDateTimeValue(
                $row,
                'email_verified_at',
            ),
            failedLoginCount: $this->integerValue(
                $row,
                'failed_login_count',
            ),
            lockedUntil: $this->nullableDateTimeValue(
                $row,
                'locked_until',
            ),
            isSuperadmin: $this->integerValue($row, 'is_superadmin') === 1,
            createdAt: new DateTimeImmutable(
                $this->stringValue($row, 'created_at'),
            ),
            updatedAt: new DateTimeImmutable(
                $this->stringValue($row, 'updated_at'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a nullable string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function integerValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer.',
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableDateTimeValue(
        array $row,
        string $key,
    ): ?DateTimeImmutable {
        $value = $this->nullableStringValue($row, $key);

        return $value === null ? null : new DateTimeImmutable($value);
    }
}
