<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

/**
 * Verifies that a ZIP-based upload is the OOXML package claimed by its media
 * type, not an arbitrary or encrypted archive wearing an Office extension.
 */
interface OfficeDocumentInspector
{
    public function matches(string $path, string $mediaType): bool;
}
