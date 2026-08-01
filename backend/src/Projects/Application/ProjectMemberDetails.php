<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

final readonly class ProjectMemberDetails
{
    /**
     * @param list<ProjectMemberRoleDetails> $roles
     */
    public function __construct(
        public string $membershipId,
        public string $userId,
        public string $email,
        public string $displayName,
        public array $roles,
    ) {}
}
