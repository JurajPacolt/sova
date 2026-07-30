<?php

declare(strict_types=1);

namespace Sova\Tests\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use Sova\Issues\Infrastructure\Storage\ZipOfficeDocumentInspector;
use ZipArchive;

final class ZipOfficeDocumentInspectorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testAcceptsOnlyTheRequiredOoxmlPackageShape(): void
    {
        $document = $this->archive([
            '[Content_Types].xml' => '<Types/>',
            '_rels/.rels' => '<Relationships/>',
            'word/document.xml' => '<document/>',
        ]);
        $inspector = new ZipOfficeDocumentInspector();

        self::assertTrue($inspector->matches(
            $document,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ));
        self::assertFalse($inspector->matches(
            $document,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ));
    }

    public function testRejectsAnArbitraryOrPathTraversingZip(): void
    {
        $plainArchive = $this->archive(['payload.txt' => 'not an office package']);
        $unsafeArchive = $this->archive([
            '[Content_Types].xml' => '<Types/>',
            '_rels/.rels' => '<Relationships/>',
            'word/document.xml' => '<document/>',
            '../outside.txt' => 'unsafe',
        ]);
        $inspector = new ZipOfficeDocumentInspector();
        $mediaType =
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        self::assertFalse($inspector->matches($plainArchive, $mediaType));
        self::assertFalse($inspector->matches($unsafeArchive, $mediaType));
    }

    /**
     * @param array<string, string> $entries
     */
    private function archive(array $entries): string
    {
        $path = sys_get_temp_dir() . '/sova-ooxml-' . bin2hex(random_bytes(8)) . '.zip';
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE));

        foreach ($entries as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }

        self::assertTrue($archive->close());
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
