<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Identity\Application\Token\ConsumedOneTimeToken;
use Sova\Identity\Application\Token\OneTimeTokenRepository;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;

final readonly class DoctrineOneTimeTokenRepository implements OneTimeTokenRepository
{
    public function __construct(private Connection $connection) {}

    public function replaceActive(
        string $tokenId,
        string $userId,
        OneTimeTokenPurpose $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $tokenId,
            $userId,
            $purpose,
            $tokenHash,
            $expiresAt,
        ): void {
            $connection->fetchOne(
                'SELECT id FROM users WHERE id = :user_id FOR UPDATE',
                ['user_id' => $userId],
            );
            $connection->executeStatement(
                <<<'SQL'
                    UPDATE user_action_tokens
                    SET revoked_at = CURRENT_TIMESTAMP
                    WHERE user_id = :user_id
                        AND purpose = :purpose
                        AND used_at IS NULL
                        AND revoked_at IS NULL
                    SQL,
                [
                    'user_id' => $userId,
                    'purpose' => $purpose->value,
                ],
            );
            $connection->insert('user_action_tokens', [
                'id' => $tokenId,
                'user_id' => $userId,
                'purpose' => $purpose->value,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function consumeActive(
        string $tokenHash,
        OneTimeTokenPurpose $purpose,
    ): ?ConsumedOneTimeToken {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE user_action_tokens
                SET used_at = CURRENT_TIMESTAMP
                WHERE token_hash = :token_hash
                    AND purpose = :purpose
                    AND used_at IS NULL
                    AND revoked_at IS NULL
                    AND expires_at > CURRENT_TIMESTAMP
                RETURNING id, user_id, purpose
                SQL,
            [
                'token_hash' => $tokenHash,
                'purpose' => $purpose->value,
            ],
        );

        if ($row === false) {
            return null;
        }

        return new ConsumedOneTimeToken(
            id: $this->stringValue($row, 'id'),
            userId: $this->stringValue($row, 'user_id'),
            purpose: OneTimeTokenPurpose::from(
                $this->stringValue($row, 'purpose'),
            ),
        );
    }

    public function findConsumed(
        string $tokenHash,
        OneTimeTokenPurpose $purpose,
    ): ?ConsumedOneTimeToken {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, user_id, purpose
                FROM user_action_tokens
                WHERE token_hash = :token_hash
                    AND purpose = :purpose
                    AND used_at IS NOT NULL
                    AND revoked_at IS NULL
                SQL,
            [
                'token_hash' => $tokenHash,
                'purpose' => $purpose->value,
            ],
        );

        if ($row === false) {
            return null;
        }

        return new ConsumedOneTimeToken(
            id: $this->stringValue($row, 'id'),
            userId: $this->stringValue($row, 'user_id'),
            purpose: OneTimeTokenPurpose::from(
                $this->stringValue($row, 'purpose'),
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
}
