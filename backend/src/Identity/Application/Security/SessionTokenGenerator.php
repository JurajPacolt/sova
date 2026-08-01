<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Security;

use InvalidArgumentException;
use SensitiveParameter;
use Sova\Identity\Domain\Session\IssuedSessionToken;

final readonly class SessionTokenGenerator
{
    private const MINIMUM_ENTROPY_BYTES = 32;

    /**
     * @var int<32, max>
     */
    private int $entropyBytes;

    public function __construct(int $entropyBytes = self::MINIMUM_ENTROPY_BYTES)
    {
        if ($entropyBytes < self::MINIMUM_ENTROPY_BYTES) {
            throw new InvalidArgumentException(
                'A session token must contain at least 256 bits of entropy.',
            );
        }

        $this->entropyBytes = $entropyBytes;
    }

    public function generate(): IssuedSessionToken
    {
        $plainText = rtrim(
            strtr(base64_encode(random_bytes($this->entropyBytes)), '+/', '-_'),
            '=',
        );

        return new IssuedSessionToken(
            $plainText,
            $this->hash($plainText),
        );
    }

    public function hash(#[SensitiveParameter] string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    public function matches(
        #[SensitiveParameter]
        string $plainText,
        string $expectedHash,
    ): bool {
        return hash_equals($expectedHash, $this->hash($plainText));
    }
}
