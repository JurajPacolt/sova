<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Sova\Shared\Application\Audit\AuditCursor;
use Sova\Shared\Application\Audit\AuditQueryValidator;
use Sova\Shared\Domain\Error\DomainProblemException;

final class AuditQueryValidatorTest extends TestCase
{
    public function testDefaultsAndValidFiltersAreNormalized(): void
    {
        $validator = new AuditQueryValidator();
        $cursor = new AuditCursor(
            new DateTimeImmutable('2026-07-27T10:15:30.123456+00:00'),
            '019c02d5-2df0-7cd1-bae6-4502f9a8534a',
        );
        $query = $validator->validate([
            'limit' => '25',
            'cursor' => $cursor->encode(),
            'from' => '2026-07-01T00:00:00+00:00',
            'to' => '2026-07-31T23:59:59+00:00',
            'actor_user_id' => '019c02d5-2df0-7cd1-bae6-4502f9a8534a',
            'event_type' => 'TENANT_ROLE_ASSIGNED',
            'outcome' => 'SUCCESS',
            'request_id' => 'request-1234',
        ]);

        self::assertSame(25, $query->limit);
        self::assertSame(
            '019c02d5-2df0-7cd1-bae6-4502f9a8534a',
            $query->cursor?->id,
        );
        self::assertSame('TENANT_ROLE_ASSIGNED', $query->eventType);
        self::assertSame('SUCCESS', $query->outcome);
        self::assertSame('request-1234', $query->requestId);
    }

    public function testInvalidFiltersReturnStableValidationProblem(): void
    {
        $validator = new AuditQueryValidator();

        try {
            $validator->validate([
                'limit' => '101',
                'cursor' => 'not-a-cursor',
                'from' => '2026-08-01T00:00:00+00:00',
                'to' => '2026-07-01T00:00:00+00:00',
                'event_type' => 'not valid',
                'outcome' => 'MAYBE',
            ]);
            self::fail('Invalid audit filters must be rejected.');
        } catch (DomainProblemException $exception) {
            self::assertSame(
                'AUDIT_QUERY_INVALID',
                $exception->problemCode(),
            );
            self::assertArrayHasKey('limit', $exception->fieldErrors());
            self::assertArrayHasKey('cursor', $exception->fieldErrors());
            self::assertArrayHasKey('event_type', $exception->fieldErrors());
            self::assertArrayHasKey('outcome', $exception->fieldErrors());
            self::assertArrayHasKey('to', $exception->fieldErrors());
        }
    }

    public function testDefaultLimitIsFifty(): void
    {
        self::assertSame(
            50,
            (new AuditQueryValidator())->validate([])->limit,
        );
    }
}
