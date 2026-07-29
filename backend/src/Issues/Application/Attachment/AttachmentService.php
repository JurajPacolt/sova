<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

use DateTimeImmutable;
use finfo;
use Sova\Issues\Application\IssueDetails;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Domain\Attachment\AttachmentPolicy;
use Sova\Issues\Domain\Attachment\ScanStatus;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Configuration\Settings;

/**
 * Accepting, serving and removing issue attachments.
 *
 * The file's type is decided by sniffing the bytes, never by trusting the
 * declared content type or the extension alone — an allowlist keyed on client
 * input is not an allowlist. The storage key is generated here from random
 * identifiers, so nothing derived from the uploaded filename ever reaches the
 * filesystem.
 *
 * Bytes are written before the row exists and the row is written before the
 * scan verdict, which means a crash can leave an orphaned object or a file
 * stuck at `PENDING`. That is the safe direction: an unreferenced blob wastes
 * space, whereas a row without bytes would be a broken download and a cleared
 * file that was never scanned would be a hole.
 */
final readonly class AttachmentService
{
    private int $quotaBytes;

    public function __construct(
        private AttachmentRepository $attachments,
        private AttachmentStorage $storage,
        private AttachmentScanner $scanner,
        private AttachmentPolicy $policy,
        private IssueRepository $issues,
        Settings $settings,
    ) {
        $quota = $settings->int('attachments.tenant_quota_bytes', 20 * 1024 * 1024 * 1024);
        $this->quotaBytes = $quota > 0 ? $quota : 20 * 1024 * 1024 * 1024;
    }

    /**
     * @return list<AttachmentDetails>
     */
    public function listForIssue(string $tenantId, string $issueId): array
    {
        return $this->attachments->listForIssue($tenantId, $issueId);
    }

    public function upload(
        IssueDetails $issue,
        UploadedAttachment $upload,
        string $uploaderMembershipId,
        string $actorUserId,
    ): AttachmentDetails {
        $this->assertWithinLimits($issue, $upload);

        $mediaType = $this->policy->resolveMediaType(
            $this->detectMediaType($upload->temporaryPath),
            $upload->originalName,
        );

        if ($mediaType === null) {
            throw $this->rejected(
                AttachmentPolicy::TYPE_NOT_ALLOWED,
                'This file type cannot be attached.',
            );
        }

        $checksum = hash_file('sha256', $upload->temporaryPath);

        if ($checksum === false) {
            throw $this->rejected(
                'ATTACHMENT_UNREADABLE',
                'The uploaded file could not be read.',
            );
        }

        $attachmentId = (string) UuidV7::generate();
        $storageKey = $this->storageKey($issue->tenantId, $attachmentId);
        $this->storage->store($storageKey, $upload->temporaryPath);

        $details = new AttachmentDetails(
            $attachmentId,
            $issue->tenantId,
            $issue->projectId,
            $issue->id,
            $this->safeName($upload->originalName),
            $storageKey,
            $mediaType,
            $upload->byteSize,
            $checksum,
            ScanStatus::Pending,
            $uploaderMembershipId,
            $actorUserId,
            null,
            new DateTimeImmutable(),
        );

        $this->attachments->create($details);

        $verdict = $this->scanner->scan($storageKey, $upload->temporaryPath);

        if ($verdict === ScanStatus::Infected) {
            // The bytes go immediately; the row stays so the history and the
            // audit still show that something was rejected.
            $this->storage->delete($storageKey);
        }

        $this->attachments->updateScanStatus($issue->tenantId, $attachmentId, $verdict);
        $this->record($issue, $attachmentId, 'ATTACHMENT_ADDED', $actorUserId, [
            'attachment_id' => $attachmentId,
            'media_type' => $mediaType,
            'scan_status' => $verdict->value,
        ]);

        if ($verdict === ScanStatus::Infected) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ATTACHMENT_INFECTED',
                'The uploaded file was rejected by the malware scanner.',
            );
        }

        return new AttachmentDetails(
            $details->id,
            $details->tenantId,
            $details->projectId,
            $details->issueId,
            $details->originalName,
            $details->storageKey,
            $details->mediaType,
            $details->byteSize,
            $details->checksum,
            $verdict,
            $details->uploadedByMembershipId,
            $details->uploadedByUserId,
            $details->uploadedByDisplayName,
            $details->createdAt,
        );
    }

    /**
     * The stored bytes, once the scanner has cleared them. A file that is still
     * pending or was found infected is refused — that is the whole point of
     * keeping it private until the scan succeeds.
     */
    public function download(AttachmentDetails $attachment): string
    {
        if (!$attachment->scanStatus->isDownloadable()) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ATTACHMENT_NOT_AVAILABLE',
                'The attachment is not available for download yet.',
            );
        }

        $contents = $this->storage->read($attachment->storageKey);

        if ($contents === null) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'ATTACHMENT_NOT_FOUND',
                'The attachment was not found.',
            );
        }

        return $contents;
    }

    /**
     * Removal is soft: the row survives its retention window so the activity
     * stream keeps making sense, but the bytes go straight away — there is no
     * reason to keep a file nobody may download.
     */
    public function delete(
        IssueDetails $issue,
        AttachmentDetails $attachment,
        string $actorUserId,
    ): void {
        $this->attachments->softDelete($issue->tenantId, $attachment->id, $actorUserId);
        $this->storage->delete($attachment->storageKey);
        $this->record($issue, $attachment->id, 'ATTACHMENT_REMOVED', $actorUserId, [
            'attachment_id' => $attachment->id,
        ]);
    }

    private function assertWithinLimits(IssueDetails $issue, UploadedAttachment $upload): void
    {
        if ($upload->byteSize < 1 || $upload->byteSize > AttachmentPolicy::MAX_BYTES) {
            throw $this->rejected(
                AttachmentPolicy::TOO_LARGE,
                sprintf(
                    'An attachment may be at most %d MiB.',
                    intdiv(AttachmentPolicy::MAX_BYTES, 1024 * 1024),
                ),
            );
        }

        if (
            $this->attachments->countForIssue($issue->tenantId, $issue->id)
            >= AttachmentPolicy::MAX_PER_ISSUE
        ) {
            throw $this->rejected(
                AttachmentPolicy::TOO_MANY,
                sprintf(
                    'An issue may carry at most %d attachments.',
                    AttachmentPolicy::MAX_PER_ISSUE,
                ),
            );
        }

        if (
            $this->attachments->usedBytes($issue->tenantId) + $upload->byteSize
            > $this->quotaBytes
        ) {
            throw $this->rejected(
                AttachmentPolicy::QUOTA_EXCEEDED,
                'The tenant attachment quota has been reached.',
            );
        }
    }

    private function detectMediaType(string $temporaryPath): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);

        return is_string($detected) && $detected !== ''
            ? $detected
            : 'application/octet-stream';
    }

    /**
     * Keeps the display name recognisable while removing anything that could be
     * read as a path or a control sequence. The name is metadata only — it never
     * takes part in addressing the stored object.
     */
    private function safeName(string $originalName): string
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment';
        }

        return mb_substr($name, 0, 255);
    }

    private function storageKey(string $tenantId, string $attachmentId): string
    {
        $shard = substr(str_replace('-', '', $attachmentId), 0, 4);

        return sprintf(
            '%s/%s/%s/%s',
            $tenantId,
            substr($shard, 0, 2),
            substr($shard, 2, 2),
            $attachmentId,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(
        IssueDetails $issue,
        string $attachmentId,
        string $eventType,
        string $actorUserId,
        array $metadata,
    ): void {
        unset($attachmentId);

        $this->issues->recordHistory(
            $issue->tenantId,
            $issue->projectId,
            $issue->id,
            $issue->version,
            $eventType,
            $actorUserId,
            null,
            null,
            null,
            $metadata,
            // Attaching a file annotates the issue; it does not change it.
            false,
        );
    }

    private function rejected(string $code, string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            $code,
            $message,
            ['file' => [$message]],
        );
    }
}
