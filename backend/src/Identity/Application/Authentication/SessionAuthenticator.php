<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Authentication;

use SensitiveParameter;
use Sova\Identity\Application\Security\SessionTokenGenerator;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class SessionAuthenticator
{
    public function __construct(
        private UserSessionRepository $sessions,
        private SessionTokenGenerator $tokenGenerator,
    ) {}

    public function authenticate(
        #[SensitiveParameter]
        ?string $plainTextToken,
    ): SessionContext {
        if ($plainTextToken === null || $plainTextToken === '') {
            throw $this->authenticationRequired();
        }

        $session = $this->sessions->findActiveByTokenHash(
            $this->tokenGenerator->hash($plainTextToken),
        );

        if ($session === null) {
            throw $this->authenticationRequired();
        }

        $this->sessions->touch($session->sessionId);

        return $session;
    }

    private function authenticationRequired(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::AuthenticationRequired,
            'SESSION_REQUIRED',
            'A valid session is required to access this resource.',
        );
    }
}
