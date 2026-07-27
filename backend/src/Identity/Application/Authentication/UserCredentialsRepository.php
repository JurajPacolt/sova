<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

interface UserCredentialsRepository
{
    public function findByNormalizedEmail(string $normalizedEmail): ?UserCredentials;

    public function findById(string $userId): ?UserCredentials;

    public function updatePasswordHash(string $userId, string $passwordHash): void;

    public function markEmailVerified(string $userId): bool;
}
