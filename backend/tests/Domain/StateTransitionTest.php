<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Tenancy\Domain\Membership\MembershipStatus;
use Sova\Tenancy\Domain\Tenant\TenantStatus;

final class StateTransitionTest extends TestCase
{
    public function testUserTransitionsRejectReactivationAfterDeletion(): void
    {
        self::assertTrue(
            UserStatus::PendingVerification->canTransitionTo(UserStatus::Active),
        );
        self::assertTrue(UserStatus::Active->canTransitionTo(UserStatus::Locked));
        self::assertTrue(UserStatus::Locked->canTransitionTo(UserStatus::Active));
        self::assertFalse(UserStatus::Active->canTransitionTo(UserStatus::Active));
        self::assertFalse(UserStatus::Deleted->canTransitionTo(UserStatus::Active));
    }

    public function testTenantTransitionsRequireTheDeletionGraceState(): void
    {
        self::assertTrue(TenantStatus::Active->canTransitionTo(TenantStatus::Archived));
        self::assertTrue(
            TenantStatus::Archived->canTransitionTo(TenantStatus::DeletionPending),
        );
        self::assertTrue(
            TenantStatus::DeletionPending->canTransitionTo(TenantStatus::Deleted),
        );
        self::assertFalse(TenantStatus::Active->canTransitionTo(TenantStatus::Deleted));
        self::assertFalse(TenantStatus::Deleted->canTransitionTo(TenantStatus::Active));
    }

    public function testRemovedMembershipCannotBeReactivated(): void
    {
        self::assertTrue(
            MembershipStatus::Active->canTransitionTo(MembershipStatus::Disabled),
        );
        self::assertTrue(
            MembershipStatus::Disabled->canTransitionTo(MembershipStatus::Active),
        );
        self::assertTrue(
            MembershipStatus::Disabled->canTransitionTo(MembershipStatus::Removed),
        );
        self::assertFalse(
            MembershipStatus::Removed->canTransitionTo(MembershipStatus::Active),
        );
    }
}
