<?php

declare(strict_types=1);

namespace Sova\Authorization\Application;

use InvalidArgumentException;

final readonly class AuthorizationSubject
{
    private function __construct(
        public string $actorUserId,
        public string $effectiveUserId,
        public bool $actorIsSuperadmin,
        public bool $isImpersonating,
    ) {
        if (
            trim($this->actorUserId) === ''
            || trim($this->effectiveUserId) === ''
        ) {
            throw new InvalidArgumentException(
                'Authorization identities must not be empty.',
            );
        }
    }

    public static function authenticated(
        string $userId,
        bool $isSuperadmin,
    ): self {
        return new self($userId, $userId, $isSuperadmin, false);
    }

    public static function impersonated(
        string $actorUserId,
        string $effectiveUserId,
        bool $actorIsSuperadmin,
    ): self {
        return new self(
            $actorUserId,
            $effectiveUserId,
            $actorIsSuperadmin,
            true,
        );
    }

    public static function contextual(
        string $actorUserId,
        string $effectiveUserId,
        bool $actorIsSuperadmin,
    ): self {
        return $actorUserId === $effectiveUserId
            ? self::authenticated($actorUserId, $actorIsSuperadmin)
            : self::impersonated(
                $actorUserId,
                $effectiveUserId,
                $actorIsSuperadmin,
            );
    }

    public function hasSuperadminBypass(): bool
    {
        return $this->actorIsSuperadmin && !$this->isImpersonating;
    }
}
