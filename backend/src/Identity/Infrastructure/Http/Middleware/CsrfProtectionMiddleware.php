<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Security\SessionTokenGenerator;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class CsrfProtectionMiddleware implements MiddlewareInterface
{
    private string $headerName;

    public function __construct(
        private SessionTokenGenerator $tokenGenerator,
        Settings $settings,
    ) {
        $this->headerName = $settings->string('auth.csrf_header_name');
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );
        $plainTextToken = trim($request->getHeaderLine($this->headerName));

        if (
            !$session instanceof SessionContext
            || $plainTextToken === ''
            || !$this->tokenGenerator->matches(
                $plainTextToken,
                $session->csrfTokenHash,
            )
        ) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'CSRF_TOKEN_INVALID',
                'The CSRF token is missing or invalid.',
            );
        }

        return $handler->handle($request);
    }
}
