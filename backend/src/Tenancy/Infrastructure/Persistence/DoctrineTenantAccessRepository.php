<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Application\Access\TenantAccessRepository;
use Sova\Tenancy\Domain\Tenant\TenantStatus;
use ValueError;

final readonly class DoctrineTenantAccessRepository implements TenantAccessRepository
{
    public function __construct(private Connection $connection) {}

    public function listAccessibleTo(
        string $userId,
        bool $isSuperadmin,
    ): array {
        if ($isSuperadmin) {
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id, name, slug, status, NULL AS membership_id
                    FROM tenants
                    WHERE status <> 'DELETED'
                    ORDER BY LOWER(name), id
                    SQL,
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT
                        tenants.id,
                        tenants.name,
                        tenants.slug,
                        tenants.status,
                        memberships.id AS membership_id
                    FROM tenant_memberships memberships
                    INNER JOIN tenants ON tenants.id = memberships.tenant_id
                    WHERE memberships.user_id = :user_id
                        AND memberships.status = 'ACTIVE'
                        AND tenants.status = 'ACTIVE'
                    ORDER BY LOWER(tenants.name), tenants.id
                    SQL,
                ['user_id' => $userId],
            );
        }

        $tenants = [];

        foreach ($rows as $row) {
            $tenants[] = $this->hydrate($row, $isSuperadmin);
        }

        return $tenants;
    }

    public function findAccessibleById(
        string $tenantId,
        string $userId,
        bool $isSuperadmin,
    ): ?AccessibleTenant {
        if ($isSuperadmin) {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT id, name, slug, status, NULL AS membership_id
                    FROM tenants
                    WHERE id = :tenant_id
                        AND status <> 'DELETED'
                    SQL,
                ['tenant_id' => $tenantId],
            );
        } else {
            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT
                        tenants.id,
                        tenants.name,
                        tenants.slug,
                        tenants.status,
                        memberships.id AS membership_id
                    FROM tenant_memberships memberships
                    INNER JOIN tenants ON tenants.id = memberships.tenant_id
                    WHERE tenants.id = :tenant_id
                        AND memberships.user_id = :user_id
                        AND memberships.status = 'ACTIVE'
                        AND tenants.status = 'ACTIVE'
                    SQL,
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                ],
            );
        }

        return $row === false ? null : $this->hydrate($row, $isSuperadmin);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, bool $viaSuperadmin): AccessibleTenant
    {
        $statusValue = $this->stringValue($row, 'status');

        try {
            $status = TenantStatus::from($statusValue);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf('Unknown tenant status "%s".', $statusValue),
                previous: $exception,
            );
        }

        return new AccessibleTenant(
            id: $this->stringValue($row, 'id'),
            name: $this->stringValue($row, 'name'),
            slug: $this->stringValue($row, 'slug'),
            status: $status,
            membershipId: $this->nullableStringValue($row, 'membership_id'),
            viaSuperadmin: $viaSuperadmin,
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

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a nullable string.',
                $key,
            ));
        }

        return $value;
    }
}
