<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;
use Sova\Tenancy\Domain\Membership\MembershipStatus;

interface InvitationRepository
{
    public function lockTenant(string $tenantId): bool;

    public function expirePendingForEmail(
        string $tenantId,
        string $normalizedEmail,
    ): void;

    public function pendingExists(
        string $tenantId,
        string $normalizedEmail,
    ): bool;

    public function activeMembershipExistsForEmail(
        string $tenantId,
        string $normalizedEmail,
    ): bool;

    public function create(
        string $invitationId,
        string $tenantId,
        string $email,
        string $normalizedEmail,
        string $invitedByUserId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        ?string $initialRoleCode = null,
    ): void;

    public function findUsableByTokenHash(
        string $tokenHash,
        bool $forUpdate = false,
    ): ?TenantInvitation;

    public function membershipStatus(
        string $tenantId,
        string $userId,
    ): ?MembershipStatus;

    public function createMembership(
        string $membershipId,
        string $tenantId,
        string $userId,
    ): void;

    public function membershipId(
        string $tenantId,
        string $userId,
    ): ?string;

    public function assignInitialRole(
        string $tenantId,
        string $membershipId,
        string $roleCode,
        string $assignedByUserId,
    ): bool;

    public function activatePendingTenant(string $tenantId): void;

    public function createVerifiedUser(
        string $userId,
        string $email,
        string $normalizedEmail,
        string $passwordHash,
        string $displayName,
        string $preferredLocale,
    ): void;

    public function accept(string $invitationId, string $userId): bool;
}
