<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Domain\User\UserStatus;

final readonly class DoctrineUserCredentialsRepository implements UserCredentialsRepository
{
    public function __construct(private Connection $connection) {}

    public function findByNormalizedEmail(string $normalizedEmail): ?UserCredentials
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id,
                    email,
                    password_hash,
                    display_name,
                    preferred_locale,
                    status,
                    email_verified_at,
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM user_system_roles system_role
                        WHERE system_role.user_id = users.id
                            AND system_role.role_code = 'SUPERADMIN'
                    ) THEN 1 ELSE 0 END AS is_superadmin
                FROM users
                WHERE normalized_email = :normalized_email
                SQL,
            ['normalized_email' => $normalizedEmail],
        );

        return $this->hydrate($row);
    }

    public function findById(string $userId): ?UserCredentials
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id,
                    email,
                    password_hash,
                    display_name,
                    preferred_locale,
                    status,
                    email_verified_at,
                    CASE WHEN EXISTS (
                        SELECT 1
                        FROM user_system_roles system_role
                        WHERE system_role.user_id = users.id
                            AND system_role.role_code = 'SUPERADMIN'
                    ) THEN 1 ELSE 0 END AS is_superadmin
                FROM users
                WHERE id = :user_id
                SQL,
            ['user_id' => $userId],
        );

        return $this->hydrate($row);
    }

    public function updatePasswordHash(string $userId, string $passwordHash): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE users
                SET password_hash = :password_hash,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
                SQL,
            [
                'password_hash' => $passwordHash,
                'user_id' => $userId,
            ],
        );
    }

    public function markEmailVerified(string $userId): bool
    {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE users
                SET status = 'ACTIVE',
                    email_verified_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
                    AND status = 'PENDING_VERIFICATION'
                    AND email_verified_at IS NULL
                SQL,
            ['user_id' => $userId],
        ) === 1;
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
     * @param array<string, mixed>|false $row
     */
    private function hydrate(array|false $row): ?UserCredentials
    {
        if ($row === false) {
            return null;
        }

        return new UserCredentials(
            id: $this->stringValue($row, 'id'),
            email: $this->stringValue($row, 'email'),
            passwordHash: $this->stringValue($row, 'password_hash'),
            displayName: $this->stringValue($row, 'display_name'),
            preferredLocale: $this->stringValue($row, 'preferred_locale'),
            status: UserStatus::from($this->stringValue($row, 'status')),
            emailVerifiedAt: $this->nullableDateTimeValue(
                $row,
                'email_verified_at',
            ),
            isSuperadmin: $this->boolValue($row, 'is_superadmin'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableDateTimeValue(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a date-time string or null.',
                $key,
            ));
        }

        return new DateTimeImmutable($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function boolValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain a boolean.',
            $key,
        ));
    }
}
