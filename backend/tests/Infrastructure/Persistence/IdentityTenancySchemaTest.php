<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Sova\Identity\Domain\Token\OneTimeTokenPurpose;
use Sova\Identity\Infrastructure\Persistence\DoctrineLoginRateLimiter;
use Sova\Identity\Infrastructure\Persistence\DoctrineOneTimeTokenRepository;
use Sova\Identity\Infrastructure\Persistence\DoctrinePublicEmailRateLimiter;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Shared\Infrastructure\Configuration\Settings;

final class IdentityTenancySchemaTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 3));
        $connection = $app->getContainer()->get(Connection::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (
            isset($this->connection)
            && $this->connection->isTransactionActive()
        ) {
            $this->connection->rollBack();
        }
    }

    public function testMembershipHasACompositeTenantIdentityConstraint(): void
    {
        $definition = $this->connection->fetchOne(<<<'SQL'
            SELECT pg_get_constraintdef(oid)
            FROM pg_constraint
            WHERE conname = 'uniq_tenant_memberships_tenant_id_id'
            SQL);

        self::assertIsString($definition);
        self::assertSame('UNIQUE (tenant_id, id)', $definition);
    }

    public function testSameUserCannotHaveTwoMembershipsInOneTenant(): void
    {
        $userId = $this->insertUser();
        $tenantId = $this->insertTenant();
        $this->insertMembership($tenantId, $userId);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertMembership($tenantId, $userId);
    }

    public function testDatabaseRejectsAnUnknownMembershipState(): void
    {
        $userId = $this->insertUser();
        $tenantId = $this->insertTenant();

        $this->expectException(DbalException::class);

        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'UNKNOWN',
        ]);
    }

    public function testDatabaseRejectsAPlainTextSessionToken(): void
    {
        $userId = $this->insertUser();

        $this->expectException(DbalException::class);

        $this->connection->insert('user_sessions', [
            'id' => (string) UuidV7::generate(),
            'user_id' => $userId,
            'token_hash' => 'plain-text-session-token',
            'csrf_token_hash' => str_repeat('a', 64),
            'expires_at' => '2099-01-01 00:00:00+00',
        ]);
    }

    public function testDatabaseRejectsAPlainTextOneTimeToken(): void
    {
        $userId = $this->insertUser();

        $this->expectException(DbalException::class);

        $this->connection->insert('user_action_tokens', [
            'id' => (string) UuidV7::generate(),
            'user_id' => $userId,
            'purpose' => OneTimeTokenPurpose::PasswordReset->value,
            'token_hash' => 'plain-text-reset-token',
            'expires_at' => '2099-01-01 00:00:00+00',
        ]);
    }

    public function testReplacingAndConsumingAOneTimeTokenIsSingleUse(): void
    {
        $userId = $this->insertUser();
        $repository = new DoctrineOneTimeTokenRepository($this->connection);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+30 minutes');
        $firstHash = str_repeat('a', 64);
        $secondHash = str_repeat('b', 64);

        $repository->replaceActive(
            (string) UuidV7::generate(),
            $userId,
            OneTimeTokenPurpose::PasswordReset,
            $firstHash,
            $expiresAt,
        );
        $repository->replaceActive(
            (string) UuidV7::generate(),
            $userId,
            OneTimeTokenPurpose::PasswordReset,
            $secondHash,
            $expiresAt,
        );

        self::assertNull($repository->consumeActive(
            $firstHash,
            OneTimeTokenPurpose::PasswordReset,
        ));
        $consumed = $repository->consumeActive(
            $secondHash,
            OneTimeTokenPurpose::PasswordReset,
        );

        self::assertNotNull($consumed);
        self::assertSame($userId, $consumed->userId);
        self::assertSame(
            OneTimeTokenPurpose::PasswordReset,
            $consumed->purpose,
        );
        self::assertNull($repository->consumeActive(
            $secondHash,
            OneTimeTokenPurpose::PasswordReset,
        ));
    }

    public function testOnlyOnePendingInvitationMayExistForATenantAndEmail(): void
    {
        $inviterId = $this->insertUser();
        $tenantId = $this->insertTenant();
        $email = 'invited@example.test';

        $this->connection->insert('tenant_invitations', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'email' => $email,
            'normalized_email' => $email,
            'invited_by_user_id' => $inviterId,
            'token_hash' => str_repeat('c', 64),
            'status' => 'PENDING',
            'expires_at' => '2099-01-01 00:00:00+00',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('tenant_invitations', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'email' => $email,
            'normalized_email' => $email,
            'invited_by_user_id' => $inviterId,
            'token_hash' => str_repeat('d', 64),
            'status' => 'PENDING',
            'expires_at' => '2099-01-01 00:00:00+00',
        ]);
    }

    public function testSuperadminIsASeparateSeededSystemRole(): void
    {
        self::assertSame(
            'SUPERADMIN',
            $this->connection->fetchOne(
                'SELECT code FROM system_roles WHERE code = :code',
                ['code' => 'SUPERADMIN'],
            ),
        );

        $userId = $this->insertUser();
        $this->connection->insert('user_system_roles', [
            'user_id' => $userId,
            'role_code' => 'SUPERADMIN',
        ]);

        self::assertSame(
            1,
            $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM user_system_roles
                    WHERE user_id = :user_id
                        AND role_code = 'SUPERADMIN'
                    SQL,
                ['user_id' => $userId],
            ),
        );
    }

    public function testLoginRateLimiterUsesAccountAndIpBuckets(): void
    {
        $rateLimiter = new DoctrineLoginRateLimiter(
            $this->connection,
            new Settings([
                'auth' => [
                    'rate_limit_secret' => 'schema-test-rate-limit-secret',
                    'rate_limit_window_seconds' => 900,
                    'rate_limit_block_seconds' => 900,
                    'rate_limit_account_attempts' => 2,
                    'rate_limit_ip_attempts' => 3,
                ],
            ]),
        );

        $rateLimiter->recordFailure('first@example.test', '203.0.113.40');
        self::assertFalse(
            $rateLimiter->isLimited('first@example.test', '203.0.113.40'),
        );

        $rateLimiter->recordFailure('first@example.test', '203.0.113.40');
        self::assertTrue(
            $rateLimiter->isLimited('first@example.test', '203.0.113.40'),
        );

        $rateLimiter->recordFailure('second@example.test', '203.0.113.41');
        $rateLimiter->recordFailure('third@example.test', '203.0.113.41');
        $rateLimiter->recordFailure('fourth@example.test', '203.0.113.41');
        self::assertTrue(
            $rateLimiter->isLimited('new@example.test', '203.0.113.41'),
        );
    }

    public function testPasswordRecoveryLimiterAllowsConfiguredCountThenSuppresses(): void
    {
        $rateLimiter = new DoctrinePublicEmailRateLimiter(
            $this->connection,
            new Settings([
                'auth' => [
                    'rate_limit_secret' => 'recovery-test-rate-limit-secret',
                    'recovery_rate_limit_window_seconds' => 3_600,
                    'recovery_rate_limit_block_seconds' => 3_600,
                    'recovery_rate_limit_account_requests' => 2,
                    'recovery_rate_limit_ip_requests' => 3,
                ],
            ]),
        );

        self::assertTrue($rateLimiter->consumeAllowance(
            OneTimeTokenPurpose::PasswordReset,
            'recovery@example.test',
            '203.0.113.50',
        ));
        self::assertTrue($rateLimiter->consumeAllowance(
            OneTimeTokenPurpose::PasswordReset,
            'recovery@example.test',
            '203.0.113.50',
        ));
        self::assertFalse($rateLimiter->consumeAllowance(
            OneTimeTokenPurpose::PasswordReset,
            'recovery@example.test',
            '203.0.113.50',
        ));
        self::assertTrue($rateLimiter->consumeAllowance(
            OneTimeTokenPurpose::EmailVerification,
            'recovery@example.test',
            '203.0.113.50',
        ));
    }

    private function insertUser(): string
    {
        $id = (string) UuidV7::generate();
        $email = sprintf('%s@example.test', str_replace('-', '', $id));

        $this->connection->insert('users', [
            'id' => $id,
            'email' => $email,
            'normalized_email' => $email,
            'password_hash' => 'test-password-hash',
            'display_name' => 'Test User',
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertTenant(): string
    {
        $id = (string) UuidV7::generate();

        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => 'Test Tenant',
            'slug' => sprintf('tenant-%s', substr(str_replace('-', '', $id), 0, 12)),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertMembership(string $tenantId, string $userId): void
    {
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'ACTIVE',
        ]);
    }
}
