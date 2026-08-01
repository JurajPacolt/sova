<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use DateTimeImmutable;
use Sova\Identity\Domain\User\UserStatus;

final readonly class UserCredentials
{
    public function __construct(
        public string $id,
        public string $email,
        public string $passwordHash,
        public string $displayName,
        public string $preferredLocale,
        public UserStatus $status,
        public ?DateTimeImmutable $emailVerifiedAt,
        public bool $isSuperadmin,
    ) {}
}
