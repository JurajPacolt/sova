<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use Sova\Notifications\Domain\NotificationKind;

/** One member an event should reach, and what it means to them. */
final readonly class Recipient
{
    public function __construct(
        public string $membershipId,
        public string $userId,
        public NotificationKind $kind,
    ) {}
}
