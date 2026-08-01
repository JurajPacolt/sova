<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Persistence;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\App;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Bootstrap\ApplicationFactory;
use Sova\Shared\Infrastructure\Persistence\TenantDatabaseScope;

/**
 * The database's own half of tenant isolation.
 *
 * Every other isolation test drives the application, so it proves that the
 * queries SOVA writes carry their predicate. This one deliberately writes the
 * **worst** query in the codebase — one with no `WHERE` at all — and asserts the
 * database refuses to answer it in full. That is the whole point of the policy:
 * it is not there for the statements that are already correct.
 */
final class RowLevelSecurityTest extends TestCase
{
    private Connection $connection;
    private TenantDatabaseScope $scope;
    private string $actorUserId;
    private string $firstTenantId;
    private string $secondTenantId;

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_TESTS') !== 'true') {
            self::markTestSkipped(
                'Set RUN_DATABASE_TESTS=true and migrate PostgreSQL before database tests.',
            );
        }

        /** @var App<Container> $app */
        $app = ApplicationFactory::create(dirname(__DIR__, 3));
        $container = $app->getContainer();
        $connection = $container->get(Connection::class);
        $scope = $container->get(TenantDatabaseScope::class);

        if (!$connection instanceof Connection) {
            self::fail('The container must provide a Doctrine DBAL connection.');
        }

        if (!$scope instanceof TenantDatabaseScope) {
            self::fail('The container must provide the tenant database scope.');
        }

        $this->connection = $connection;
        $this->scope = $scope;

        // A role that bypasses the policies makes every assertion below pass or
        // fail for the wrong reason, so the reason is named here rather than
        // left to be guessed from "expected 1, got 2".
        if ($this->roleBypassesPolicies()) {
            self::fail(
                'The database role bypasses row level security, so the policies '
                . 'do not apply. The application role must be NOSUPERUSER and '
                . 'NOBYPASSRLS (ADR 0010).',
            );
        }

        $this->connection->beginTransaction();
        $this->actorUserId = $this->insertUser();
        $this->firstTenantId = $this->insertTenant('rls-first');
        $this->secondTenantId = $this->insertTenant('rls-second');
        $this->insertWorkgroup($this->firstTenantId, 'First group');
        $this->insertWorkgroup($this->secondTenantId, 'Second group');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testAScopedQueryWithoutAPredicateOnlySeesItsOwnTenant(): void
    {
        $unscoped = $this->workgroupNames();
        self::assertContains('First group', $unscoped);
        self::assertContains('Second group', $unscoped);

        $scoped = $this->scope->within(
            $this->firstTenantId,
            fn(): array => $this->workgroupNames(),
        );

        self::assertSame(['First group'], $scoped);
    }

    public function testTheScopeIsClearedEvenWhenTheWorkFails(): void
    {
        $reported = '';

        try {
            $this->scope->within($this->firstTenantId, static function (): void {
                throw new RuntimeException('The work failed.');
            });
        } catch (RuntimeException $exception) {
            $reported = $exception->getMessage();
        }

        // The scope must not swallow, or replace, what the work threw.
        self::assertSame('The work failed.', $reported);
        // A scope left behind would silently narrow the next request on a reused
        // connection — the exact confusion the policy exists to prevent.
        self::assertContains('Second group', $this->workgroupNames());
    }

    public function testAScopedWriteCannotPlaceARowInAnotherTenant(): void
    {
        $this->expectException(DbalException::class);

        $this->scope->within($this->firstTenantId, function (): void {
            $this->insertWorkgroup($this->secondTenantId, 'Smuggled group');
        });
    }

    /**
     * A tenant-scoped request may still record a system-level event, so the
     * write side of the two nullable tables is deliberately more permissive
     * than the read side.
     */
    public function testASystemAuditEventStaysWritableUnderATenantScopeButUnreadable(): void
    {
        $eventId = (string) UuidV7::generate();

        $this->scope->within($this->firstTenantId, function () use ($eventId): void {
            $this->connection->insert('security_audit_events', [
                'id' => $eventId,
                'actor_user_id' => $this->actorUserId,
                'tenant_id' => null,
                'event_type' => 'SYSTEM_EVENT_UNDER_TENANT_SCOPE',
                'outcome' => 'SUCCESS',
                'reason_code' => 'TEST',
                'request_id' => 'rls-test',
            ]);

            self::assertSame(0, $this->countAuditEvent($eventId));
        });

        self::assertSame(1, $this->countAuditEvent($eventId));
    }

    /**
     * @return list<string>
     */
    private function workgroupNames(): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT name FROM workgroups ORDER BY name',
        );

        return $names;
    }

    private function countAuditEvent(string $eventId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM security_audit_events WHERE id = :id',
            ['id' => $eventId],
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    private function roleBypassesPolicies(): bool
    {
        return $this->connection->fetchOne(
            'SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = current_user',
        ) === true;
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
            'display_name' => 'Row level security test user',
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertTenant(string $name): string
    {
        $id = (string) UuidV7::generate();

        $this->connection->insert('tenants', [
            'id' => $id,
            'name' => $name,
            'slug' => sprintf('%s-%s', $name, substr(str_replace('-', '', $id), 0, 12)),
            'status' => 'ACTIVE',
        ]);

        return $id;
    }

    private function insertWorkgroup(string $tenantId, string $name): void
    {
        $this->connection->insert('workgroups', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => '',
            'status' => 'ACTIVE',
        ]);
    }
}
