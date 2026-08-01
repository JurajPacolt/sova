<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Security;

use InvalidArgumentException;
use SensitiveParameter;
use Sova\Identity\Domain\Token\IssuedOneTimeToken;

final readonly class OneTimeTokenGenerator
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
                'A one-time token must contain at least 256 bits of entropy.',
            );
        }

        $this->entropyBytes = $entropyBytes;
    }

    public function generate(): IssuedOneTimeToken
    {
        $plainText = rtrim(
            strtr(base64_encode(random_bytes($this->entropyBytes)), '+/', '-_'),
            '=',
        );

        return new IssuedOneTimeToken(
            $plainText,
            $this->hash($plainText),
        );
    }

    public function hash(#[SensitiveParameter] string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    public function hasValidFormat(
        #[SensitiveParameter]
        string $plainText,
    ): bool {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $plainText) === 1;
    }
}
