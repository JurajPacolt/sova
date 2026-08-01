<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

use DateTimeImmutable;
use Sova\Tenancy\Domain\Tenant\TenantStatus;

interface SystemTenantRepository
{
    /**
     * @return list<SystemTenantDetails>
     */
    public function listAll(): array;

    public function findById(
        string $tenantId,
        bool $forUpdate = false,
    ): ?SystemTenantDetails;

    public function findCreationRecord(
        string $actorUserId,
        string $idempotencyKey,
    ): ?SystemTenantCreationRecord;

    public function create(
        string $tenantId,
        string $name,
        string $slug,
    ): void;

    public function rememberCreation(
        string $actorUserId,
        string $idempotencyKey,
        string $requestFingerprint,
        string $tenantId,
    ): void;

    public function activeOwnerCount(string $tenantId): int;

    public function changeStatus(
        string $tenantId,
        int $expectedRevision,
        TenantStatus $targetStatus,
        ?DateTimeImmutable $deletionRequestedAt,
        ?DateTimeImmutable $deletionEffectiveAt,
    ): bool;
}
