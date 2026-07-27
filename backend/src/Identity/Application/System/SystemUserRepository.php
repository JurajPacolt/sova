<?php

declare(strict_types=1);

namespace Sova\Identity\Application\System;

use Sova\Identity\Domain\User\UserStatus;

interface SystemUserRepository
{
    /**
     * @return list<SystemUserDetails>
     */
    public function listAll(): array;

    public function findById(
        string $userId,
        bool $forUpdate = false,
    ): ?SystemUserDetails;

    public function changeStatus(string $userId, UserStatus $status): void;

    public function grantSuperadmin(
        string $userId,
        string $grantedByUserId,
    ): void;

    public function revokeSuperadmin(string $userId): void;

    public function activeSuperadminCount(bool $forUpdate = false): int;
}
