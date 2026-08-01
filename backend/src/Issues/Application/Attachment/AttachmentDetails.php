<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

use DateTimeImmutable;
use Sova\Issues\Domain\Attachment\ScanStatus;

final readonly class AttachmentDetails
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $projectId,
        public string $issueId,
        public string $originalName,
        public string $storageKey,
        public string $mediaType,
        public int $byteSize,
        public string $checksum,
        public ScanStatus $scanStatus,
        public string $uploadedByMembershipId,
        public ?string $uploadedByUserId,
        public ?string $uploadedByDisplayName,
        public DateTimeImmutable $createdAt,
    ) {}
}
