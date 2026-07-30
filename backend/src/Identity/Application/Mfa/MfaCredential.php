<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

use DateTimeImmutable;

final readonly class MfaCredential
{
    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function __construct(
        public string $userId,
        public string $secretKeyId,
        public string $encryptedSecret,
        public ?DateTimeImmutable $enabledAt,
        public array $recoveryCodeHashes,
        public ?int $lastUsedStep,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabledAt !== null;
    }
}
