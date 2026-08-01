<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

final readonly class MemberContact
{
    public function __construct(
        public string $email,
        public string $displayName,
        public string $locale,
    ) {}
}
