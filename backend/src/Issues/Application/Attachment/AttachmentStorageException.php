<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Attachment;

use RuntimeException;

/** The attachment bytes could not be written to or read from storage. */
final class AttachmentStorageException extends RuntimeException {}
