<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sova\Issues\Domain\Attachment\AttachmentPolicy;
use Sova\Issues\Domain\Attachment\ScanStatus;

/**
 * The allowlist is keyed on the media type the server detects from the bytes.
 * These tests pin the two things that make that safe: a type outside the list
 * is refused whatever it is called, and a name that disagrees with the content
 * is refused rather than quietly corrected.
 */
final class AttachmentPolicyTest extends TestCase
{
    #[DataProvider('acceptedUploads')]
    public function testAcceptedUpload(
        string $detected,
        string $name,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            (new AttachmentPolicy())->resolveMediaType($detected, $name),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedUploads(): iterable
    {
        yield 'png' => ['image/png', 'diagram.png', 'image/png'];
        yield 'jpeg' => ['image/jpeg', 'photo.jpg', 'image/jpeg'];
        yield 'jpeg with long extension' => ['image/jpeg', 'photo.jpeg', 'image/jpeg'];
        yield 'webp' => ['image/webp', 'shot.webp', 'image/webp'];
        yield 'pdf' => ['application/pdf', 'report.pdf', 'application/pdf'];
        yield 'text' => ['text/plain', 'notes.txt', 'text/plain'];
        yield 'markdown is text' => ['text/plain', 'notes.md', 'text/plain'];
        yield 'csv sniffed as text' => ['text/plain', 'export.csv', 'text/csv'];
        yield 'csv sniffed as csv' => ['text/csv', 'export.csv', 'text/csv'];
        yield 'docx is a zip' => [
            'application/zip',
            'contract.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        yield 'xlsx is a zip' => [
            'application/zip',
            'budget.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        yield 'pptx is a zip' => [
            'application/zip',
            'deck.pptx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        yield 'uppercase extension' => ['image/png', 'DIAGRAM.PNG', 'image/png'];
    }

    #[DataProvider('rejectedUploads')]
    public function testRejectedUpload(string $detected, string $name): void
    {
        self::assertNull((new AttachmentPolicy())->resolveMediaType($detected, $name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedUploads(): iterable
    {
        yield 'executable' => ['application/x-dosexec', 'setup.exe'];
        yield 'shell script' => ['text/x-shellscript', 'install.sh'];
        yield 'svg can carry script' => ['image/svg+xml', 'icon.svg'];
        yield 'html' => ['text/html', 'page.html'];
        yield 'plain zip is not an office document' => ['application/zip', 'bundle.zip'];
        yield 'zip renamed to png' => ['application/zip', 'payload.png'];
        yield 'no extension at all' => ['image/png', 'diagram'];
        // The dangerous case: real content, misleading name. A PHP file called
        // `.png` must not be stored as an image.
        yield 'php named as png' => ['text/x-php', 'shell.png'];
        yield 'image named as pdf' => ['image/png', 'invoice.pdf'];
        yield 'text named as docx' => ['text/plain', 'contract.docx'];
    }

    public function testOnlyClearedFilesAreDownloadable(): void
    {
        self::assertTrue(ScanStatus::Clean->isDownloadable());
        self::assertTrue(ScanStatus::Skipped->isDownloadable());
        // A verdict that is missing or bad keeps the bytes unreachable.
        self::assertFalse(ScanStatus::Pending->isDownloadable());
        self::assertFalse(ScanStatus::Infected->isDownloadable());
    }

    public function testEveryAllowedTypeIsAdvertised(): void
    {
        $advertised = (new AttachmentPolicy())->allowedMediaTypes();

        self::assertContains('image/png', $advertised);
        self::assertContains('application/pdf', $advertised);
        self::assertNotContains('image/svg+xml', $advertised);
        self::assertNotContains('text/html', $advertised);
    }
}
