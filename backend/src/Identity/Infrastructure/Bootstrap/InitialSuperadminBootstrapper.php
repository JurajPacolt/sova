<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Bootstrap;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use SensitiveParameter;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Application\Security\PasswordPolicy;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Creates the first installation administrator without opening a public
 * registration or unauthenticated HTTP bootstrap endpoint.
 *
 * The database advisory lock makes concurrent invocations deterministic. Once
 * any SUPERADMIN grant exists, this path is permanently closed.
 */
final readonly class InitialSuperadminBootstrapper
{
    /**
     * @var list<string>
     */
    private const array SUPPORTED_LOCALES = ['sk', 'cs', 'en', 'de', 'pl', 'hu'];

    public function __construct(
        private Connection $connection,
        private PasswordHasher $passwordHasher,
        private PasswordPolicy $passwordPolicy,
        private SecurityAuditRecorder $audit,
    ) {}

    public function bootstrap(
        string $email,
        string $displayName,
        string $locale,
        #[SensitiveParameter]
        string $password,
    ): string {
        $normalizedEmail = strtolower(trim($email));
        $displayName = trim($displayName);
        $locale = strtolower(trim($locale));

        if (
            $normalizedEmail === ''
            || strlen($normalizedEmail) > 254
            || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException('Bootstrap email is invalid.');
        }

        if (
            $displayName === ''
            || mb_strlen($displayName, 'UTF-8') > 160
        ) {
            throw new RuntimeException('Display name must contain at most 160 characters.');
        }

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new RuntimeException(
                'Locale must be one of: sk, cs, en, de, pl, hu.',
            );
        }

        $userId = (string) UuidV7::generate();
        $verifiedAt = new DateTimeImmutable();
        $candidate = new UserCredentials(
            id: $userId,
            email: $normalizedEmail,
            passwordHash: '',
            displayName: $displayName,
            preferredLocale: $locale,
            status: UserStatus::Active,
            emailVerifiedAt: $verifiedAt,
            isSuperadmin: true,
        );
        $this->passwordPolicy->assertAcceptable($password, $candidate);
        $passwordHash = $this->passwordHasher->hash($password);

        $this->connection->transactional(function () use (
            $displayName,
            $locale,
            $normalizedEmail,
            $passwordHash,
            $userId,
            $verifiedAt,
        ): void {
            $this->connection->executeQuery(
                "SELECT pg_advisory_xact_lock(hashtext('sova.initial-superadmin'))",
            );

            $grantExists = $this->count(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM user_system_roles
                    WHERE role_code = 'SUPERADMIN'
                    SQL,
            );

            if ($grantExists !== 0) {
                throw new RuntimeException(
                    'Initial bootstrap is closed because a SUPERADMIN grant already exists.',
                );
            }

            $accountExists = $this->count(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM users
                    WHERE normalized_email = :normalized_email
                    SQL,
                ['normalized_email' => $normalizedEmail],
            );

            if ($accountExists !== 0) {
                throw new RuntimeException(
                    'Initial bootstrap refuses to elevate an existing account.',
                );
            }

            $this->connection->insert('users', [
                'id' => $userId,
                'email' => $normalizedEmail,
                'normalized_email' => $normalizedEmail,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'preferred_locale' => $locale,
                'status' => UserStatus::Active->value,
                'email_verified_at' => $verifiedAt->format('Y-m-d H:i:s.uP'),
            ]);
            $this->connection->insert('user_system_roles', [
                'user_id' => $userId,
                'role_code' => 'SUPERADMIN',
                'granted_by_user_id' => null,
            ]);
            $this->audit->record(
                eventType: 'INITIAL_SUPERADMIN_BOOTSTRAPPED',
                outcome: 'SUCCESS',
                reasonCode: 'INSTALLATION_BOOTSTRAP',
                requestId: 'bootstrap-cli',
                actorUserId: $userId,
                effectiveUserId: $userId,
                metadata: ['email' => $normalizedEmail],
            );
        });

        return $userId;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function count(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('The database returned an invalid count.');
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw new RuntimeException('The database returned an invalid count.');
            }

            return $value;
        }

        if (!ctype_digit($value)) {
            throw new RuntimeException('The database returned an invalid count.');
        }

        return (int) $value;
    }
}
