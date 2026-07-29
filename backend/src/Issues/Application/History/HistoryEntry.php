<?php

declare(strict_types=1);

namespace Sova\Issues\Application\History;

use DateTimeImmutable;

/**
 * One entry of the **user-facing** issue history. This is not the security
 * audit: it exists so a reader can understand how the issue evolved, and it is
 * therefore readable with `issue.view` alone. Anything that needs tamper
 * evidence or administrative oversight belongs in `security_audit_events`.
 */
final readonly class HistoryEntry
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $issueId,
        public int $issueVersion,
        public string $eventType,
        public ?string $actorUserId,
        public ?string $actorDisplayName,
        public ?string $fromStatusCode,
        public ?string $fromStatusName,
        public ?string $toStatusCode,
        public ?string $toStatusName,
        public array $metadata,
        public DateTimeImmutable $createdAt,
    ) {}
}
