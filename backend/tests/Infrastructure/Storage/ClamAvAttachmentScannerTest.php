<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Sova\Issues\Domain\Attachment\ScanStatus;
use Sova\Issues\Infrastructure\Storage\ClamAvAttachmentScanner;
use Sova\Issues\Infrastructure\Storage\ClamAvClient;
use Throwable;

final class ClamAvAttachmentScannerTest extends TestCase
{
    #[DataProvider('verdicts')]
    public function testMapsOnlyExplicitClamAvVerdicts(
        string $response,
        ScanStatus $expected,
    ): void {
        $scanner = new ClamAvAttachmentScanner(
            new StubClamAvClient($response),
            new NullLogger(),
        );

        self::assertSame($expected, $scanner->scan('tenant/attachment', '/tmp/upload'));
    }

    public function testUnavailableScannerLeavesTheAttachmentPending(): void
    {
        $scanner = new ClamAvAttachmentScanner(
            new StubClamAvClient(new RuntimeException('clamd is unavailable')),
            new NullLogger(),
        );

        self::assertSame(
            ScanStatus::Pending,
            $scanner->scan('tenant/attachment', '/tmp/upload'),
        );
    }

    /**
     * @return iterable<string, array{string, ScanStatus}>
     */
    public static function verdicts(): iterable
    {
        yield 'clean' => ['stream: OK', ScanStatus::Clean];
        yield 'infected' => ['stream: Eicar-Signature FOUND', ScanStatus::Infected];
        yield 'daemon error' => ['stream: INSTREAM size limit exceeded. ERROR', ScanStatus::Pending];
        yield 'unknown response' => ['PONG', ScanStatus::Pending];
    }
}

final readonly class StubClamAvClient implements ClamAvClient
{
    public function __construct(private string|Throwable $result) {}

    public function scan(string $path): string
    {
        unset($path);

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
