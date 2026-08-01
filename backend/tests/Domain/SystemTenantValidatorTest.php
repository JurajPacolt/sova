<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Tenancy\Application\System\SystemTenantInputValidator;
use Sova\Tenancy\Application\System\SystemTenantLifecycleValidator;
use Sova\Tenancy\Domain\Tenant\TenantStatus;

final class SystemTenantValidatorTest extends TestCase
{
    public function testNormalizesTenantCreationInput(): void
    {
        $input = (new SystemTenantInputValidator())->validate([
            'name' => '  SOVA Support  ',
            'slug' => 'sova-support',
            'owner_email' => ' Owner@Example.Test ',
        ]);

        self::assertSame('SOVA Support', $input->name);
        self::assertSame('sova-support', $input->slug);
        self::assertSame('owner@example.test', $input->ownerEmail);
    }

    public function testRejectsInvalidCreationInputAndUnknownFields(): void
    {
        try {
            (new SystemTenantInputValidator())->validate([
                'name' => '',
                'slug' => 'Not Valid',
                'owner_email' => 'invalid',
                'status' => 'ACTIVE',
            ]);
            self::fail('Invalid tenant input must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'SYSTEM_TENANT_INPUT_INVALID',
                $exception->problemCode(),
            );
            self::assertSame(
                ['body', 'name', 'slug', 'owner_email'],
                array_keys($exception->fieldErrors()),
            );
        }
    }

    public function testValidatesLifecycleRevisionReasonAndTarget(): void
    {
        $input = (new SystemTenantLifecycleValidator())->validate([
            'status' => 'SUSPENDED',
            'revision' => 3,
            'reason' => '  Confirmed security incident response.  ',
        ]);

        self::assertSame(TenantStatus::Suspended, $input->status);
        self::assertSame(3, $input->revision);
        self::assertSame(
            'Confirmed security incident response.',
            $input->reason,
        );

        try {
            (new SystemTenantLifecycleValidator())->validate([
                'status' => 'DELETED',
                'revision' => 0,
                'reason' => 'short',
                'unexpected' => true,
            ]);
            self::fail('Unsafe lifecycle input must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'SYSTEM_TENANT_LIFECYCLE_INPUT_INVALID',
                $exception->problemCode(),
            );
            self::assertSame(
                ['body', 'status', 'revision', 'reason'],
                array_keys($exception->fieldErrors()),
            );
        }
    }
}
