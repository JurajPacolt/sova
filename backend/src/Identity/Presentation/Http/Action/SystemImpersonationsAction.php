<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Impersonation\ImpersonationInputValidator;
use Sova\Identity\Application\Impersonation\ImpersonationService;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Presentation\Http\ImpersonationSerializer;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class SystemImpersonationsAction
{
    public function __construct(
        private ImpersonationService $impersonations,
        private ImpersonationInputValidator $validator,
        private ImpersonationSerializer $serializer,
        private AuthorizationService $authorization,
        private SecurityAuditRecorder $audit,
    ) {}

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
        $session = $this->session($request);

        if ($request->getMethod() === 'DELETE') {
            $this->impersonations->endCurrent(
                $session,
                $this->requestId($request),
                $this->ipAddress($request),
            );

            return $response->withStatus(204);
        }

        if ($request->getMethod() !== 'POST') {
            throw new RuntimeException(
                'Unsupported system impersonation operation.',
            );
        }

        $input = null;

        try {
            $this->authorization->require(
                AuthorizationSubject::contextual(
                    $session->actorUserId,
                    $session->userId,
                    $session->actorIsSuperadmin,
                ),
                Permission::SystemImpersonate,
                AuthorizationScope::system(),
            );
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $input = $this->validator->validate($payload);
            $impersonation = $this->impersonations->start(
                $session,
                $input,
                $this->requestId($request),
                $this->ipAddress($request),
            );
        } catch (DomainProblemException $exception) {
            $this->audit->record(
                eventType: 'IMPERSONATION_STARTED',
                outcome: 'FAILURE',
                reasonCode: $exception->problemCode(),
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                ipAddress: $this->ipAddress($request),
                metadata: [
                    'requested_tenant_id' => $input?->tenantId,
                    'requested_effective_user_id' => $input?->effectiveUserId,
                ],
            );

            throw $exception;
        }

        return JsonResponse::write(
            $response,
            [
                'user' => [
                    'id' => $impersonation->effectiveUserId,
                    'email' => $impersonation->effectiveUserEmail,
                    'display_name' => $impersonation
                        ->effectiveUserDisplayName,
                    'preferred_locale' => $impersonation
                        ->effectiveUserPreferredLocale,
                    'is_superadmin' => false,
                ],
                'impersonation' => $this->serializer->serialize(
                    $impersonation,
                ),
            ],
            201,
        );
    }

    private function session(ServerRequestInterface $request): SessionContext
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'System impersonation requires a session context.',
            );
        }

        return $session;
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
    }

    private function ipAddress(ServerRequestInterface $request): ?string
    {
        $value = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_IP) !== false
                ? $value
                : null;
    }
}
