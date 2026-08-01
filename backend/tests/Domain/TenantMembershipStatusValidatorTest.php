<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Tenancy\Application\Membership\TenantMembershipStatusValidator;
use Sova\Tenancy\Domain\Membership\MembershipStatus;

final class TenantMembershipStatusValidatorTest extends TestCase
{
    public function testAcceptsAnExplicitMembershipStatus(): void
    {
        self::assertSame(
            MembershipStatus::Disabled,
            (new TenantMembershipStatusValidator())->validate([
                'status' => 'DISABLED',
            ]),
        );
    }

    public function testRejectsUnknownFieldsAndUnknownStatus(): void
    {
        try {
            (new TenantMembershipStatusValidator())->validate([
                'status' => 'PAUSED',
                'role' => 'TENANT_OWNER',
            ]);
            self::fail('An invalid membership status must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'TENANT_MEMBERSHIP_INPUT_INVALID',
                $exception->problemCode(),
            );
            self::assertArrayHasKey('body', $exception->fieldErrors());
            self::assertArrayHasKey(
                'status',
                $exception->fieldErrors(),
            );
        }
    }
}
