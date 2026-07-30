<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Tenancy\Application\Settings\TenantSettingsDetails;
use Sova\Tenancy\Application\Settings\TenantSettingsRepository;

final readonly class DoctrineTenantSettingsRepository implements TenantSettingsRepository
{
    public function __construct(private Connection $connection) {}

    public function find(
        string $tenantId,
        bool $forUpdate = false,
    ): ?TenantSettingsDetails {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, name, slug, default_locale, timezone, revision
                FROM tenants
                WHERE id = :tenant_id
                    AND status <> 'DELETED'
                SQL
            . ($forUpdate ? "\nFOR UPDATE" : ''),
            ['tenant_id' => $tenantId],
        );

        return $row === false ? null : new TenantSettingsDetails(
            tenantId: $this->stringValue($row, 'id'),
            name: $this->stringValue($row, 'name'),
            slug: $this->stringValue($row, 'slug'),
            defaultLocale: $this->stringValue($row, 'default_locale'),
            timezone: $this->stringValue($row, 'timezone'),
            revision: $this->integerValue($row, 'revision'),
        );
    }

    public function updateGeneral(
        string $tenantId,
        int $expectedRevision,
        string $name,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenants
                SET name = :name,
                    revision = revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :tenant_id
                    AND revision = :expected_revision
                SQL,
            [
                'name' => $name,
                'tenant_id' => $tenantId,
                'expected_revision' => $expectedRevision,
            ],
        ) === 1;
    }

    public function updateLocalization(
        string $tenantId,
        int $expectedRevision,
        string $defaultLocale,
        string $timezone,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenants
                SET default_locale = :default_locale,
                    timezone = :timezone,
                    revision = revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :tenant_id
                    AND revision = :expected_revision
                SQL,
            [
                'default_locale' => $defaultLocale,
                'timezone' => $timezone,
                'tenant_id' => $tenantId,
                'expected_revision' => $expectedRevision,
            ],
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
}
