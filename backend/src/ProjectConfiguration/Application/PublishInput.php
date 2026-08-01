<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * A publish request: the expected project configuration revision (optimistic
 * lock) and, for every removed status still carrying issues, the target status
 * code those issues migrate to (§8.2).
 */
final readonly class PublishInput
{
    /**
     * @param array<string, string> $statusMapping removed status code => target status code
     */
    public function __construct(
        public int $expectedConfigVersion,
        public array $statusMapping,
    ) {}
}
