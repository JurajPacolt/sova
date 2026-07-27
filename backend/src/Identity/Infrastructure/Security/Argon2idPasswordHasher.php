<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Security;

use InvalidArgumentException;
use SensitiveParameter;
use Sova\Identity\Application\Security\PasswordHasher;

final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    public function __construct(
        private int $memoryCost = PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        private int $timeCost = PASSWORD_ARGON2_DEFAULT_TIME_COST,
        private int $threads = PASSWORD_ARGON2_DEFAULT_THREADS,
    ) {
        if ($memoryCost <= 0 || $timeCost <= 0 || $threads <= 0) {
            throw new InvalidArgumentException(
                'Argon2id cost parameters must be positive integers.',
            );
        }
    }

    public function hash(#[SensitiveParameter] string $plainTextPassword): string
    {
        return password_hash(
            $plainTextPassword,
            PASSWORD_ARGON2ID,
            $this->options(),
        );
    }

    public function verify(
        #[SensitiveParameter]
        string $plainTextPassword,
        string $passwordHash,
    ): bool {
        return password_verify($plainTextPassword, $passwordHash);
    }

    public function needsRehash(string $passwordHash): bool
    {
        return password_needs_rehash(
            $passwordHash,
            PASSWORD_ARGON2ID,
            $this->options(),
        );
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        return [
            'memory_cost' => $this->memoryCost,
            'time_cost' => $this->timeCost,
            'threads' => $this->threads,
        ];
    }
}
