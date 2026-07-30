<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class MfaAction
{
    public function __construct(
        private GetMfaStatusAction $getStatus,
        private DisableMfaAction $disable,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return match ($request->getMethod()) {
            'GET' => ($this->getStatus)($request, $response, $args),
            'DELETE' => ($this->disable)($request, $response, $args),
            default => throw new RuntimeException(
                'Unsupported MFA status operation.',
            ),
        };
    }
}
