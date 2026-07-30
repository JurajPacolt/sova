<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

use DateTimeImmutable;

interface MfaCredentialRepository
{
    public function find(string $userId): ?MfaCredential;

    public function findForUpdate(string $userId): ?MfaCredential;

    public function replacePending(
        string $userId,
        string $secretKeyId,
        string $encryptedSecret,
    ): void;

    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function enable(
        string $userId,
        DateTimeImmutable $enabledAt,
        array $recoveryCodeHashes,
        int $lastUsedStep,
    ): bool;

    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function updateVerificationState(
        string $userId,
        ?int $lastUsedStep,
        array $recoveryCodeHashes,
    ): void;

    public function delete(string $userId): bool;
}
