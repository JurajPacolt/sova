<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http;

use Sova\Issues\Application\Attachment\AttachmentDetails;

final readonly class AttachmentSerializer
{
    /**
     * The storage key is deliberately absent: it is an internal address, and
     * publishing it would invite callers to build their own paths to the bytes.
     *
     * @return array<string, mixed>
     */
    public function serialize(AttachmentDetails $attachment): array
    {
        return [
            'id' => $attachment->id,
            'issue_id' => $attachment->issueId,
            'name' => $attachment->originalName,
            'media_type' => $attachment->mediaType,
            'byte_size' => $attachment->byteSize,
            'checksum' => $attachment->checksum,
            'scan_status' => $attachment->scanStatus->value,
            'downloadable' => $attachment->scanStatus->isDownloadable(),
            'uploaded_by' => [
                'membership_id' => $attachment->uploadedByMembershipId,
                'display_name' => $attachment->uploadedByDisplayName,
            ],
            'created_at' => $attachment->createdAt->format(DATE_ATOM),
        ];
    }
}
