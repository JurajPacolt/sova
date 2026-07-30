<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Bootstrap;

use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\App;
use Sova\Identity\Infrastructure\Bootstrap\InitialSuperadminBootstrapper;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;

final class InitialSuperadminBootstrapperTest extends TestCase
{
    private Connection $connection;
    private InitialSuperadminBootstrapper $bootstrapper;

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
        $bootstrapper = $app->getContainer()->get(
            InitialSuperadminBootstrapper::class,
        );

        if (
            !$connection instanceof Connection
            || !$bootstrapper instanceof InitialSuperadminBootstrapper
        ) {
            self::fail('The container must provide bootstrap dependencies.');
        }

        $this->connection = $connection;
        $this->bootstrapper = $bootstrapper;
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            "DELETE FROM user_system_roles WHERE role_code = 'SUPERADMIN'",
        );
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

    public function testCreatesAuditedInitialSuperadminAndPermanentlyClosesPath(): void
    {
        $userId = $this->bootstrapper->bootstrap(
            ' First.Admin@Example.Test ',
            ' First Admin ',
            'SK',
            'a unique staging bootstrap passphrase',
        );

        $user = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    users.email,
                    users.display_name,
                    users.preferred_locale,
                    users.status,
                    users.email_verified_at,
                    role.role_code
                FROM users
                INNER JOIN user_system_roles role
                    ON role.user_id = users.id
                WHERE users.id = :user_id
                SQL,
            ['user_id' => $userId],
        );

        self::assertIsArray($user);
        self::assertSame('first.admin@example.test', $user['email']);
        self::assertSame('First Admin', $user['display_name']);
        self::assertSame('sk', $user['preferred_locale']);
        self::assertSame('ACTIVE', $user['status']);
        self::assertNotNull($user['email_verified_at']);
        self::assertSame('SUPERADMIN', $user['role_code']);
        $auditCount = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM security_audit_events
                WHERE actor_user_id = :user_id
                    AND event_type = 'INITIAL_SUPERADMIN_BOOTSTRAPPED'
                    AND outcome = 'SUCCESS'
                SQL,
            ['user_id' => $userId],
        );
        self::assertTrue(is_numeric($auditCount));
        self::assertSame(1, (int) $auditCount);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Initial bootstrap is closed');

        $this->bootstrapper->bootstrap(
            'second.admin@example.test',
            'Second Admin',
            'en',
            'another unique staging passphrase',
        );
    }
}
