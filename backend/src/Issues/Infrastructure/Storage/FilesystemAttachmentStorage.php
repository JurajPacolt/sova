<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Storage;

use RuntimeException;
use Sova\Issues\Application\Attachment\AttachmentStorage;
use Sova\Issues\Application\Attachment\AttachmentStorageException;
use Sova\Shared\Infrastructure\Configuration\Settings;

/**
 * Stores attachment bytes under a configured directory.
 *
 * The directory must sit outside the web root — the default `var/attachments`
 * does, and `var/` is not served by `public/index.php` — because a file that
 * the web server can reach directly would bypass every authorisation check in
 * the download endpoint.
 *
 * Keys are validated on the way in *and* on the way out even though the service
 * generates them. That is deliberate belt and braces: it means a future caller
 * that gets sloppy about key construction cannot turn this class into a path
 * traversal primitive.
 */
final readonly class FilesystemAttachmentStorage implements AttachmentStorage
{
    /** Server-generated shape: `<tenant>/<aa>/<bb>/<uuid>`. Nothing else. */
    private const string KEY_PATTERN =
        '#^[0-9a-f-]{36}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f-]{36}$#';

    private string $basePath;

    public function __construct(Settings $settings)
    {
        $configured = trim($settings->string('attachments.path', ''));

        if ($configured === '') {
            throw new RuntimeException(
                'ATTACHMENT_STORAGE_PATH must point at a writable directory.',
            );
        }

        $this->basePath = rtrim($configured, '/');
    }

    public function store(string $storageKey, string $temporaryPath): void
    {
        $target = $this->absolutePath($storageKey);
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new AttachmentStorageException(
                'The attachment storage directory could not be created.',
            );
        }

        // `rename` keeps the write atomic when the upload temp directory shares
        // the filesystem; the copy is the fallback when it does not.
        if (!@rename($temporaryPath, $target) && !@copy($temporaryPath, $target)) {
            throw new AttachmentStorageException(
                'The attachment could not be written to storage.',
            );
        }

        // Readable only by the application user: the bytes are private until
        // the download endpoint says otherwise.
        @chmod($target, 0o640);
    }

    public function read(string $storageKey): ?string
    {
        $target = $this->absolutePath($storageKey);

        if (!is_file($target)) {
            return null;
        }

        $contents = @file_get_contents($target);

        return $contents === false ? null : $contents;
    }

    public function delete(string $storageKey): void
    {
        $target = $this->absolutePath($storageKey);

        if (is_file($target)) {
            @unlink($target);
        }
    }

    private function absolutePath(string $storageKey): string
    {
        if (preg_match(self::KEY_PATTERN, $storageKey) !== 1) {
            throw new AttachmentStorageException(
                'The attachment storage key has an unexpected shape.',
            );
        }

        return $this->basePath . '/' . $storageKey;
    }
}
