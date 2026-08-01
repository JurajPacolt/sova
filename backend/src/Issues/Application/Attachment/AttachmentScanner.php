<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

use Sova\Issues\Domain\Attachment\ScanStatus;

/**
 * Decides whether stored bytes are safe to hand back out.
 *
 * An implementation that cannot reach its scanner must return
 * {@see ScanStatus::Pending} rather than guessing: an unavailable scanner keeps
 * the file quarantined, it does not clear it.
 */
interface AttachmentScanner
{
    public function scan(string $storageKey, string $temporaryPath): ScanStatus;
}
