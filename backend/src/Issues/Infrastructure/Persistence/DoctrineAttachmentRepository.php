<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Exception;
use Sova\Issues\Application\Attachment\AttachmentDetails;
use Sova\Issues\Application\Attachment\AttachmentRepository;
use Sova\Issues\Domain\Attachment\ScanStatus;

/**
 * Every statement is keyed by tenant, and every read filters out soft-deleted
 * rows, so a removed attachment cannot be downloaded by identifier.
 */
final readonly class DoctrineAttachmentRepository implements AttachmentRepository
{
    public function __construct(private Connection $connection) {}

    public function listForIssue(string $tenantId, string $issueId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->selectSql() . <<<'SQL'
                    AND attachment.issue_id = :issue_id
                ORDER BY attachment.created_at ASC, attachment.id ASC
                SQL,
            ['tenant_id' => $tenantId, 'issue_id' => $issueId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $tenantId, string $attachmentId): ?AttachmentDetails
    {
        $row = $this->connection->fetchAssociative(
            $this->selectSql() . "\n    AND attachment.id = :attachment_id",
            ['tenant_id' => $tenantId, 'attachment_id' => $attachmentId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function countForIssue(string $tenantId, string $issueId): int
    {
        return $this->number(
            <<<'SQL'
                SELECT count(*)
                FROM issue_attachments
                WHERE tenant_id = :tenant_id
                    AND issue_id = :issue_id
                    AND deleted_at IS NULL
                SQL,
            ['tenant_id' => $tenantId, 'issue_id' => $issueId],
        );
    }

    public function usedBytes(string $tenantId): int
    {
        return $this->number(
            <<<'SQL'
                SELECT COALESCE(sum(byte_size), 0)
                FROM issue_attachments
                WHERE tenant_id = :tenant_id
                    AND deleted_at IS NULL
                SQL,
            ['tenant_id' => $tenantId],
        );
    }

    public function create(AttachmentDetails $attachment): void
    {
        $this->connection->insert('issue_attachments', [
            'id' => $attachment->id,
            'tenant_id' => $attachment->tenantId,
            'project_id' => $attachment->projectId,
            'issue_id' => $attachment->issueId,
            'original_name' => $attachment->originalName,
            'storage_key' => $attachment->storageKey,
            'media_type' => $attachment->mediaType,
            'byte_size' => $attachment->byteSize,
            'checksum' => $attachment->checksum,
            'scan_status' => $attachment->scanStatus->value,
            'uploaded_by_membership_id' => $attachment->uploadedByMembershipId,
            'uploaded_by_user_id' => $attachment->uploadedByUserId,
        ]);
    }

    public function updateScanStatus(
        string $tenantId,
        string $attachmentId,
        ScanStatus $status,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE issue_attachments
                SET scan_status = :scan_status,
                    scanned_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :attachment_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'attachment_id' => $attachmentId,
                'scan_status' => $status->value,
            ],
        );
    }

    public function softDelete(
        string $tenantId,
        string $attachmentId,
        string $deletedByUserId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE issue_attachments
                SET deleted_at = CURRENT_TIMESTAMP,
                    deleted_by_user_id = :deleted_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :attachment_id
                    AND deleted_at IS NULL
                SQL,
            [
                'tenant_id' => $tenantId,
                'attachment_id' => $attachmentId,
                'deleted_by' => $deletedByUserId,
            ],
        );
    }

    private function selectSql(): string
    {
        return <<<'SQL'
            SELECT attachment.id,
                   attachment.tenant_id,
                   attachment.project_id,
                   attachment.issue_id,
                   attachment.original_name,
                   attachment.storage_key,
                   attachment.media_type,
                   attachment.byte_size,
                   attachment.checksum,
                   attachment.scan_status,
                   attachment.uploaded_by_membership_id,
                   attachment.uploaded_by_user_id,
                   uploader.display_name AS uploaded_by_display_name,
                   attachment.created_at
            FROM issue_attachments attachment
            LEFT JOIN users uploader
                ON uploader.id = attachment.uploaded_by_user_id
            WHERE attachment.tenant_id = :tenant_id
                AND attachment.deleted_at IS NULL
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AttachmentDetails
    {
        return new AttachmentDetails(
            $this->string($row, 'id'),
            $this->string($row, 'tenant_id'),
            $this->string($row, 'project_id'),
            $this->string($row, 'issue_id'),
            $this->string($row, 'original_name'),
            $this->string($row, 'storage_key'),
            $this->string($row, 'media_type'),
            (int) $this->string($row, 'byte_size'),
            $this->string($row, 'checksum'),
            ScanStatus::tryFrom($this->string($row, 'scan_status')) ?? ScanStatus::Pending,
            $this->string($row, 'uploaded_by_membership_id'),
            $this->nullableString($row, 'uploaded_by_user_id'),
            $this->nullableString($row, 'uploaded_by_display_name'),
            $this->moment($this->string($row, 'created_at')),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function number(string $sql, array $parameters): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    private function moment(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }
}
