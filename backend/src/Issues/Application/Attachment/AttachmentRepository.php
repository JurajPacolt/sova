<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

use Sova\Issues\Domain\Attachment\ScanStatus;

interface AttachmentRepository
{
    /**
     * Live attachments of one issue, oldest first.
     *
     * @return list<AttachmentDetails>
     */
    public function listForIssue(string $tenantId, string $issueId): array;

    /** Live attachment, or null when it is missing or already removed. */
    public function find(string $tenantId, string $attachmentId): ?AttachmentDetails;

    public function countForIssue(string $tenantId, string $issueId): int;

    /** Bytes currently held by the tenant across every live attachment. */
    public function usedBytes(string $tenantId): int;

    public function create(AttachmentDetails $attachment): void;

    public function updateScanStatus(
        string $tenantId,
        string $attachmentId,
        ScanStatus $status,
    ): void;

    public function softDelete(
        string $tenantId,
        string $attachmentId,
        string $deletedByUserId,
    ): void;
}
