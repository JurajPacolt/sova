<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Mfa\MfaService;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class GetMfaStatusAction
{
    public function __construct(private MfaService $mfa) {}

    /**
     * @param array<string, string> $args
     *
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException('MFA status requires a session context.');
        }

        $status = $this->mfa->status($session);

        return JsonResponse::write($response, [
            'mfa' => [
                'enabled' => $status->enabled,
                'verified' => $status->verified,
                'enrollment_required' => $status->enrollmentRequired,
                'recovery_codes_remaining' => $status
                    ->recoveryCodesRemaining,
            ],
        ])->withHeader('Cache-Control', 'no-store');
    }
}
