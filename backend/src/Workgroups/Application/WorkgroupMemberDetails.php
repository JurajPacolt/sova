<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

use DateTimeImmutable;
use Sova\Workgroups\Domain\WorkgroupMemberRole;

final readonly class WorkgroupMemberDetails
{
    public function __construct(
        public string $membershipId,
        public string $userId,
        public string $email,
        public string $displayName,
        public WorkgroupMemberRole $role,
        public DateTimeImmutable $joinedAt,
    ) {}
}
