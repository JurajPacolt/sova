<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Persistence;

use DI\Container;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Tenancy\Infrastructure\Persistence\DoctrineTenantAccessRepository;

final class TenantAccessRepositoryTest extends TestCase
{
    private Connection $connection;
    private DoctrineTenantAccessRepository $repository;
    private string $userId;

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
        $this->repository = new DoctrineTenantAccessRepository($connection);
        $this->userId = $this->insertUser();
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

    public function testRegularAccessRequiresActiveMembershipAndTenant(): void
    {
        $activeTenant = $this->insertTenant('Active', 'ACTIVE');
        $foreignTenant = $this->insertTenant('Foreign', 'ACTIVE');
        $disabledTenant = $this->insertTenant('Disabled', 'ACTIVE');
        $suspendedTenant = $this->insertTenant('Suspended', 'SUSPENDED');
        $this->insertMembership($activeTenant, 'ACTIVE');
        $this->insertMembership($disabledTenant, 'DISABLED');
        $this->insertMembership($suspendedTenant, 'ACTIVE');

        $tenants = $this->repository->listAccessibleTo($this->userId, false);

        self::assertCount(1, $tenants);
        self::assertSame($activeTenant, $tenants[0]->id);
        self::assertNotNull($tenants[0]->membershipId);
        self::assertFalse($tenants[0]->viaSuperadmin);
        self::assertNull($this->repository->findAccessibleById(
            $foreignTenant,
            $this->userId,
            false,
        ));
        self::assertNull($this->repository->findAccessibleById(
            $disabledTenant,
            $this->userId,
            false,
        ));
        self::assertNull($this->repository->findAccessibleById(
            $suspendedTenant,
            $this->userId,
            false,
        ));
    }

    public function testSuperadminAccessDoesNotRequireMembership(): void
    {
        $activeTenant = $this->insertTenant('Active', 'ACTIVE');
        $suspendedTenant = $this->insertTenant('Suspended', 'SUSPENDED');
        $deletedTenant = $this->insertTenant('Deleted', 'DELETED');

        $tenants = $this->repository->listAccessibleTo($this->userId, true);
        $tenantIds = array_map(
            static fn($tenant): string => $tenant->id,
            $tenants,
        );

        self::assertContains($activeTenant, $tenantIds);
        self::assertContains($suspendedTenant, $tenantIds);
        self::assertNotContains($deletedTenant, $tenantIds);

        $suspended = $this->repository->findAccessibleById(
            $suspendedTenant,
            $this->userId,
            true,
        );

        self::assertNotNull($suspended);
        self::assertTrue($suspended->viaSuperadmin);
        self::assertNull($suspended->membershipId);
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

    private function insertTenant(string $name, string $status): string
    {
        $id = (string) UuidV7::generate();

        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => $name,
            'slug' => sprintf(
                '%s-%s',
                strtolower($name),
                substr(str_replace('-', '', $id), 0, 8),
            ),
            'status' => $status,
        ]);

        return $id;
    }

    private function insertMembership(string $tenantId, string $status): void
    {
        $this->connection->insert('tenant_memberships', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'user_id' => $this->userId,
            'status' => $status,
        ]);
    }
}
