<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

/**
 * Where attachment bytes live.
 *
 * The port exists so the store can be swapped for private object storage at
 * deployment without touching a line of the upload rules. Implementations must
 * treat the storage key as an opaque server-generated identifier and must never
 * accept a path assembled from anything the client sent.
 */
interface AttachmentStorage
{
    /**
     * Moves an uploaded temporary file into storage under the given key.
     *
     * @throws AttachmentStorageException when the bytes cannot be stored
     */
    public function store(string $storageKey, string $temporaryPath): void;

    /**
     * The stored bytes, or null when the object is gone.
     */
    public function read(string $storageKey): ?string;

    /**
     * Removes the bytes. Missing is success — deletion has to be idempotent so
     * a retry after a partial failure can finish the job.
     */
    public function delete(string $storageKey): void;
}
