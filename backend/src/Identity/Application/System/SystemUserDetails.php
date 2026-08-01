<?php

declare(strict_types=1);

namespace Sova\Identity\Application\System;

use DateTimeImmutable;
use Sova\Identity\Domain\User\UserStatus;

final readonly class SystemUserDetails
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public UserStatus $status,
        public string $preferredLocale,
        public ?DateTimeImmutable $emailVerifiedAt,
        public int $failedLoginCount,
        public ?DateTimeImmutable $lockedUntil,
        public bool $isSuperadmin,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
