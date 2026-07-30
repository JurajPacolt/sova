<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;

interface InvitationAdministrationRepository
{
    /**
     * @return list<ManagedTenantInvitation>
     */
    public function listForTenant(string $tenantId): array;

    public function findForTenant(
        string $tenantId,
        string $invitationId,
        bool $forUpdate = false,
    ): ?ManagedTenantInvitation;

    public function resendRetryAfter(
        string $invitationId,
        int $cooldownSeconds,
    ): int;

    public function replacePendingToken(
        string $tenantId,
        string $invitationId,
        string $tokenHash,
    ): bool;

    public function changePendingExpiry(
        string $tenantId,
        string $invitationId,
        DateTimeImmutable $expiresAt,
    ): bool;

    public function revokePending(
        string $tenantId,
        string $invitationId,
    ): bool;

    public function cancelPendingDeliveries(string $invitationId): void;
}
