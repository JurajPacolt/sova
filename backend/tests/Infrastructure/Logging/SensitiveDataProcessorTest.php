<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Logging;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sova\Shared\Infrastructure\Logging\SensitiveDataProcessor;

final class SensitiveDataProcessorTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function process(array $context): array
    {
        $processor = new SensitiveDataProcessor();
        $record = $processor(new LogRecord(
            new DateTimeImmutable('2026-07-29T00:00:00Z'),
            'SOVA Test API',
            Level::Warning,
            'Something happened',
            $context,
        ));

        return $record->context;
    }

    public function testASecretIsRedactedWhateverItsValueLooksLike(): void
    {
        $cleaned = $this->process([
            'password' => 'a perfectly ordinary sentence',
            'csrf_token' => 'abc',
            'authorization' => 'Basic dXNlcjpwYXNz',
            'sensitive_payload_key' => 'k',
            'password_hash' => '$argon2id$v=19$...',
        ]);

        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['password']);
        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['csrf_token']);
        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['authorization']);
        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['sensitive_payload_key']);
        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['password_hash']);
    }

    /** Saying "the password was empty" is still saying something about it. */
    public function testAnAbsentSecretIsStillRedacted(): void
    {
        $cleaned = $this->process(['password' => null, 'token' => '']);

        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['password']);
        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $cleaned['token']);
    }

    /**
     * A redactor that eats identifiers is a redactor people switch off, so the
     * fields an operator actually needs have to survive untouched.
     */
    public function testTheFieldsAnOperatorNeedsSurvive(): void
    {
        $cleaned = $this->process([
            'request_id' => '019f9f00-0000-7000-8000-000000000003',
            'tenant_id' => '019f9f00-0000-7000-8000-000000000001',
            'status' => 500,
            'path' => '/api/v1/tenants/019f9f00/issues/search',
            'problem_code' => 'QUERY_TIMEOUT',
        ]);

        self::assertSame('019f9f00-0000-7000-8000-000000000003', $cleaned['request_id']);
        self::assertSame('019f9f00-0000-7000-8000-000000000001', $cleaned['tenant_id']);
        self::assertSame(500, $cleaned['status']);
        self::assertSame('/api/v1/tenants/019f9f00/issues/search', $cleaned['path']);
        self::assertSame('QUERY_TIMEOUT', $cleaned['problem_code']);
    }

    public function testNestedSecretsAreRedactedToo(): void
    {
        $cleaned = $this->process([
            'request' => [
                'headers' => ['cookie' => 'sova_session=abc', 'accept' => 'application/json'],
                'method' => 'POST',
            ],
        ]);

        $request = $cleaned['request'];
        self::assertIsArray($request);
        $headers = $request['headers'];
        self::assertIsArray($headers);

        self::assertSame(SensitiveDataProcessor::PLACEHOLDER, $headers['cookie']);
        self::assertSame('application/json', $headers['accept']);
        self::assertSame('POST', $request['method']);
    }

    /** The address is why the line was written; the person is not. */
    public function testAnEmailIsMaskedRatherThanDropped(): void
    {
        $cleaned = $this->process([
            'email' => 'jana.novakova@example.test',
            'owner_email' => 'x@example.test',
        ]);

        self::assertSame('j***@example.test', $cleaned['email']);
        self::assertSame('x***@example.test', $cleaned['owner_email']);
    }

    /** A secret that arrives inside a string logged for another reason. */
    public function testASecretInsideAStringIsMasked(): void
    {
        $cleaned = $this->process([
            'detail' => 'Called with Authorization: Bearer eyJhbGciOi.J9.sig and it failed',
            'referer' => 'https://sova.test/accept?token=Zm9vYmFy&lang=sk',
        ]);

        self::assertSame(
            'Called with Authorization: Bearer [redacted] and it failed',
            $cleaned['detail'],
        );
        self::assertSame(
            'https://sova.test/accept?token=[redacted]&lang=sk',
            $cleaned['referer'],
        );
    }

    /** A cycle must cost a bounded amount of work, not the process. */
    public function testDeepStructuresStopAtTheDepthLimit(): void
    {
        $deep = ['leaf' => 'value'];

        for ($level = 0; $level < 20; ++$level) {
            $deep = ['next' => $deep];
        }

        $cleaned = $this->process($deep);

        self::assertArrayHasKey('next', $cleaned);
    }
}
