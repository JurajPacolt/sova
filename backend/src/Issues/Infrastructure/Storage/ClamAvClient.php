<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Storage;

/**
 * Small transport boundary around clamd's INSTREAM protocol.
 *
 * Keeping the socket behind an interface makes the verdict mapping testable
 * without starting a daemon in the unit-test process.
 */
interface ClamAvClient
{
    public function scan(string $path): string;
}
