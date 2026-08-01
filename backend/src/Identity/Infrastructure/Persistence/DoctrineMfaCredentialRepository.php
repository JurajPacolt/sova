<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;
use Sova\Identity\Application\Mfa\MfaCredential;
use Sova\Identity\Application\Mfa\MfaCredentialRepository;

final readonly class DoctrineMfaCredentialRepository implements MfaCredentialRepository
{
    public function __construct(private Connection $connection) {}

    public function find(string $userId): ?MfaCredential
    {
        return $this->fetch($userId, false);
    }

    public function findForUpdate(string $userId): ?MfaCredential
    {
        return $this->fetch($userId, true);
    }

    public function replacePending(
        string $userId,
        string $secretKeyId,
        string $encryptedSecret,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO user_mfa_credentials (
                    user_id,
                    secret_key_id,
                    encrypted_secret
                )
                VALUES (
                    :user_id,
                    :secret_key_id,
                    :encrypted_secret
                )
                ON CONFLICT (user_id) DO UPDATE
                SET secret_key_id = EXCLUDED.secret_key_id,
                    encrypted_secret = EXCLUDED.encrypted_secret,
                    recovery_code_hashes = '[]'::jsonb,
                    last_used_step = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_mfa_credentials.enabled_at IS NULL
                SQL,
            [
                'user_id' => $userId,
                'secret_key_id' => $secretKeyId,
                'encrypted_secret' => $encryptedSecret,
            ],
        );
    }

    public function enable(
        string $userId,
        DateTimeImmutable $enabledAt,
        array $recoveryCodeHashes,
        int $lastUsedStep,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_mfa_credentials
                SET enabled_at = :enabled_at,
                    recovery_code_hashes = CAST(:recovery_code_hashes AS jsonb),
                    last_used_step = :last_used_step,
                    updated_at = :enabled_at
                WHERE user_id = :user_id
                    AND enabled_at IS NULL
                SQL,
            [
                'user_id' => $userId,
                'enabled_at' => $enabledAt->format('Y-m-d H:i:s.uP'),
                'recovery_code_hashes' => $this->encodeHashes($recoveryCodeHashes),
                'last_used_step' => $lastUsedStep,
            ],
        ) === 1;
    }

    public function updateVerificationState(
        string $userId,
        ?int $lastUsedStep,
        array $recoveryCodeHashes,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE user_mfa_credentials
                SET last_used_step = :last_used_step,
                    recovery_code_hashes = CAST(:recovery_code_hashes AS jsonb),
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :user_id
                    AND enabled_at IS NOT NULL
                SQL,
            [
                'user_id' => $userId,
                'last_used_step' => $lastUsedStep,
                'recovery_code_hashes' => $this->encodeHashes($recoveryCodeHashes),
            ],
        );
    }

    public function delete(string $userId): bool
    {
        return $this->connection->delete(
            'user_mfa_credentials',
            ['user_id' => $userId],
        ) === 1;
    }

    private function fetch(string $userId, bool $forUpdate): ?MfaCredential
    {
        $sql = <<<'SQL'
            SELECT
                user_id,
                secret_key_id,
                encrypted_secret,
                enabled_at,
                recovery_code_hashes,
                last_used_step
            FROM user_mfa_credentials
            WHERE user_id = :user_id
            SQL;

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative(
            $sql,
            ['user_id' => $userId],
        );

        if ($row === false) {
            return null;
        }

        return new MfaCredential(
            userId: $this->stringValue($row, 'user_id'),
            secretKeyId: $this->stringValue($row, 'secret_key_id'),
            encryptedSecret: $this->stringValue($row, 'encrypted_secret'),
            enabledAt: $this->nullableDateTimeValue($row, 'enabled_at'),
            recoveryCodeHashes: $this->hashesValue(
                $row,
                'recovery_code_hashes',
            ),
            lastUsedStep: $this->nullableIntValue($row, 'last_used_step'),
        );
    }

    /**
     * @param list<string> $hashes
     *
     * @throws JsonException
     */
    private function encodeHashes(array $hashes): string
    {
        return json_encode(array_values($hashes), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function hashesValue(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain JSON.',
                $key,
            ));
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Stored MFA recovery-code hashes are invalid.',
                previous: $exception,
            );
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(
                'Stored MFA recovery-code hashes must be a list.',
            );
        }

        $hashes = [];

        foreach ($decoded as $hash) {
            if (
                !is_string($hash)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            ) {
                throw new RuntimeException(
                    'A stored MFA recovery-code hash is invalid.',
                );
            }

            $hashes[] = $hash;
        }

        return $hashes;
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
    private function nullableDateTimeValue(
        array $row,
        string $key,
    ): ?DateTimeImmutable {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a date-time or null.',
                $key,
            ));
        }

        return new DateTimeImmutable($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableIntValue(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return intval($value);
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer or null.',
            $key,
        ));
    }
}
