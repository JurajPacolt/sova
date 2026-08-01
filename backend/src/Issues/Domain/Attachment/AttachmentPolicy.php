<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\Attachment;

/**
 * The MVP upload contract: at most 25 MiB per file, one file per request, 20
 * live attachments per issue and a default tenant quota of 20 GiB, with a fixed
 * allowlist of formats.
 *
 * The allowlist is keyed by the media type the server **detects from the
 * bytes**, not by the one the client declared and not by the file extension.
 * A declared type is only ever cross-checked against the detected one; on its
 * own it is untrusted input.
 */
final class AttachmentPolicy
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    public const int MAX_PER_ISSUE = 20;

    public const string TOO_LARGE = 'ATTACHMENT_TOO_LARGE';

    public const string TYPE_NOT_ALLOWED = 'ATTACHMENT_TYPE_NOT_ALLOWED';

    public const string TOO_MANY = 'ATTACHMENT_LIMIT_REACHED';

    public const string QUOTA_EXCEEDED = 'ATTACHMENT_QUOTA_EXCEEDED';

    /**
     * Detected media type to the extensions it may legitimately carry.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'md', 'log'],
        'text/csv' => ['csv'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
    ];

    /**
     * OOXML files are ZIP containers, so content sniffing reports them as
     * `application/zip`. The extension then decides which OOXML type it is —
     * but only among these three, and only when the bytes really are a ZIP.
     *
     * @var array<string, string>
     */
    private const array OOXML_BY_EXTENSION = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * Resolves the media type to store, or null when the upload is not one of
     * the allowed formats.
     */
    public function resolveMediaType(string $detected, string $originalName): ?string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // `text/csv` is frequently sniffed as plain text, and a CSV is plain
        // text, so the extension is allowed to narrow it — never to widen it.
        if ($detected === 'text/plain' && $extension === 'csv') {
            return 'text/csv';
        }

        if ($detected === 'application/zip' || $detected === 'application/octet-stream') {
            return self::OOXML_BY_EXTENSION[$extension] ?? null;
        }

        $extensions = self::ALLOWED[$detected] ?? null;

        if ($extensions === null) {
            return null;
        }

        // A mismatched extension is refused rather than corrected: an image
        // named `.pdf` is at best a mistake and at worst an attempt to have it
        // opened by the wrong handler.
        return in_array($extension, $extensions, true) ? $detected : null;
    }

    /**
     * @return list<string> the media types clients may advertise
     */
    public function allowedMediaTypes(): array
    {
        return array_keys(self::ALLOWED);
    }
}
