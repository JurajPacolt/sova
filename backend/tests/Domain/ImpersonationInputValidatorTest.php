<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Identity\Application\Impersonation\ImpersonationInputValidator;
use Sova\Shared\Domain\Error\DomainProblemException;

final class ImpersonationInputValidatorTest extends TestCase
{
    public function testItNormalizesAValidInput(): void
    {
        $input = (new ImpersonationInputValidator())->validate([
            'tenant_id' => '019f9f00-0000-7000-8000-000000000001',
            'effective_user_id' => '019f9f00-0000-7000-8000-000000000002',
            'reason' => '  Investigating support request SOVA-42.  ',
            'password' => 'current password',
        ]);

        self::assertSame(
            '019f9f00-0000-7000-8000-000000000001',
            $input->tenantId,
        );
        self::assertSame(
            'Investigating support request SOVA-42.',
            $input->reason,
        );
        self::assertSame('current password', $input->password);
    }

    public function testItReturnsStableFieldErrors(): void
    {
        try {
            (new ImpersonationInputValidator())->validate([
                'tenant_id' => 'not-a-uuid',
                'effective_user_id' => '',
                'reason' => 'short',
                'password' => '',
            ]);
            self::fail('Invalid impersonation input must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'IMPERSONATION_INPUT_INVALID',
                $exception->problemCode(),
            );
            self::assertSame(
                [
                    'tenant_id',
                    'effective_user_id',
                    'reason',
                    'password',
                ],
                array_keys($exception->fieldErrors()),
            );
        }
    }
}
