<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

/**
 * One incoming file, already written to a temporary path by the HTTP layer.
 * The name is what the client claimed and is treated as untrusted display text;
 * the size is what the server measured, not what the client announced.
 */
final readonly class UploadedAttachment
{
    public function __construct(
        public string $originalName,
        public string $temporaryPath,
        public int $byteSize,
    ) {}
}
