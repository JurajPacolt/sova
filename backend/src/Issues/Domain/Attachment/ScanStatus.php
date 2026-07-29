<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\Attachment;

/**
 * Where an upload stands with the malware scanner.
 *
 * Only {@see self::Clean} and {@see self::Skipped} may be downloaded. `Pending`
 * means the verdict is not in yet and `Infected` means it never will be — both
 * stay unreadable, which is the "private and unavailable until the scan
 * succeeds" rule of the MVP contract.
 */
enum ScanStatus: string
{
    case Pending = 'PENDING';
    case Clean = 'CLEAN';
    case Infected = 'INFECTED';

    /**
     * No scanner is configured. Production refuses to boot in this state, so it
     * can only occur in development, where it is recorded honestly rather than
     * disguised as a clean verdict.
     */
    case Skipped = 'SKIPPED';

    public function isDownloadable(): bool
    {
        return $this === self::Clean || $this === self::Skipped;
    }
}
