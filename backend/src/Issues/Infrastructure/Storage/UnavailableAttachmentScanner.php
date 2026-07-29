<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Storage;

use Psr\Log\LoggerInterface;
use Sova\Issues\Application\Attachment\AttachmentScanner;
use Sova\Issues\Domain\Attachment\ScanStatus;

/**
 * The stand-in used when no malware scanner is wired up.
 *
 * It records {@see ScanStatus::Skipped} rather than pretending the file came
 * back clean, so the gap is visible in the data instead of hidden in it. The
 * container refuses to build this scanner in production — the same guard the
 * mailer uses for a null transport — which keeps it a development convenience
 * and not a silent hole.
 */
final readonly class UnavailableAttachmentScanner implements AttachmentScanner
{
    public function __construct(private LoggerInterface $logger) {}

    public function scan(string $storageKey, string $temporaryPath): ScanStatus
    {
        unset($temporaryPath);

        $this->logger->warning('Attachment stored without a malware scan.', [
            'storage_key' => $storageKey,
            'scan_status' => ScanStatus::Skipped->value,
        ]);

        return ScanStatus::Skipped;
    }
}
