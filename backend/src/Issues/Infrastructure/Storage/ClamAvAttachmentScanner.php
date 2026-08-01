<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Storage;

use Psr\Log\LoggerInterface;
use Sova\Issues\Application\Attachment\AttachmentScanner;
use Sova\Issues\Domain\Attachment\ScanStatus;
use Throwable;

/**
 * Fail-closed verdict mapping for clamd.
 *
 * An unavailable daemon or an unrecognised response leaves the attachment in
 * quarantine as PENDING. Only clamd's explicit OK response clears it.
 */
final readonly class ClamAvAttachmentScanner implements AttachmentScanner
{
    public function __construct(
        private ClamAvClient $client,
        private LoggerInterface $logger,
    ) {}

    public function scan(string $storageKey, string $temporaryPath): ScanStatus
    {
        try {
            $response = $this->client->scan($temporaryPath);
        } catch (Throwable) {
            $this->logger->error('Attachment malware scan is unavailable.', [
                'storage_key' => $storageKey,
                'scan_status' => ScanStatus::Pending->value,
            ]);

            return ScanStatus::Pending;
        }

        if (preg_match('/:\\s+OK$/u', $response) === 1) {
            return ScanStatus::Clean;
        }

        if (preg_match('/:\\s+.+\\s+FOUND$/u', $response) === 1) {
            return ScanStatus::Infected;
        }

        $this->logger->warning('Attachment malware scan returned no verdict.', [
            'storage_key' => $storageKey,
            'scan_status' => ScanStatus::Pending->value,
        ]);

        return ScanStatus::Pending;
    }
}
