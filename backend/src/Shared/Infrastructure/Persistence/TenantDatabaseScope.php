<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

/**
 * Tells PostgreSQL which tenant the current work belongs to.
 *
 * The row level security policies read `sova.tenant_id`; this is the only place
 * that writes it. Everything about the class is shaped by one rule: the setting
 * must never outlive the piece of work it was set for. A value left behind on a
 * pooled or reused connection would scope somebody else's request to the wrong
 * tenant, which is the exact failure the policies exist to prevent — so the
 * reset lives in `finally` and runs even when the work throws.
 *
 * The value travels as a **bound parameter** to `set_config`, not interpolated
 * into a `SET` statement, because `SET` takes no parameters and a tenant
 * identifier reaching SQL as text is a place for the identifier to stop being
 * one.
 *
 * An unset setting means "no tenant scope", which the policies read as
 * unrestricted. That is deliberate: login, tenant selection, system
 * administration and the background workers all have to see across tenants, and
 * a scope they never set is how they say so.
 */
final readonly class TenantDatabaseScope
{
    private const string SETTING = 'sova.tenant_id';

    public function __construct(private Connection $connection) {}

    /**
     * Runs the operation with the tenant scope applied, and clears it after.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function within(string $tenantId, callable $operation): mixed
    {
        $this->apply($tenantId);

        try {
            return $operation();
        } finally {
            // A failed reset must not replace the original outcome: the caller
            // is owed the answer, or the error, that the work produced.
            try {
                $this->apply('');
            } catch (Throwable) {
                // Ignored on purpose — see above.
            }
        }
    }

    private function apply(string $tenantId): void
    {
        $this->connection->executeQuery(
            'SELECT set_config(?, ?, false)',
            [self::SETTING, $tenantId],
            [ParameterType::STRING, ParameterType::STRING],
        )->free();
    }
}
