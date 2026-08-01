<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

final readonly class SecurityAuditPage
{
    /**
     * @param list<SecurityAuditEventDetails> $events
     */
    public function __construct(
        public array $events,
        public ?string $nextCursor,
    ) {}
}
