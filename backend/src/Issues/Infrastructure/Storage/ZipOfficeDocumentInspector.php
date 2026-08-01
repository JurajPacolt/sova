<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Storage;

use Sova\Issues\Application\Attachment\OfficeDocumentInspector;
use ZipArchive;

final class ZipOfficeDocumentInspector implements OfficeDocumentInspector
{
    private const int MAX_ENTRIES = 10_000;

    private const int MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    /**
     * @var array<string, string>
     */
    private const array REQUIRED_PARTS = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            => 'word/document.xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            => 'xl/workbook.xml',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            => 'ppt/presentation.xml',
    ];

    public function matches(string $path, string $mediaType): bool
    {
        $requiredPart = self::REQUIRED_PARTS[$mediaType] ?? null;

        if ($requiredPart === null || !is_file($path)) {
            return false;
        }

        $archive = new ZipArchive();

        if ($archive->open($path, ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_ENTRIES) {
                return false;
            }

            $uncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $entry = $archive->statIndex($index);

                if ($entry === false) {
                    return false;
                }

                $name = $entry['name'] ?? null;
                $size = $entry['size'] ?? null;
                $encryptionMethod = $entry['encryption_method'] ?? ZipArchive::EM_NONE;

                if (
                    !is_string($name)
                    || !is_int($size)
                    || !is_int($encryptionMethod)
                    || $encryptionMethod !== ZipArchive::EM_NONE
                    || !$this->safeEntryName($name)
                    || $this->isSymbolicLink($archive, $index)
                ) {
                    return false;
                }

                $uncompressedBytes += $size;

                if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    return false;
                }
            }

            return $archive->locateName('[Content_Types].xml') !== false
                && $archive->locateName('_rels/.rels') !== false
                && $archive->locateName($requiredPart) !== false;
        } finally {
            $archive->close();
        }
    }

    private function safeEntryName(string $name): bool
    {
        if (
            $name === ''
            || str_contains($name, "\0")
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
        ) {
            return false;
        }

        return !in_array('..', explode('/', $name), true);
    }

    private function isSymbolicLink(ZipArchive $archive, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;

        if (
            !$archive->getExternalAttributesIndex(
                $index,
                $operatingSystem,
                $attributes,
            )
        ) {
            return false;
        }

        unset($operatingSystem);

        if (!is_int($attributes)) {
            return true;
        }

        return (($attributes >> 16) & 0xf000) === 0xa000;
    }
}
