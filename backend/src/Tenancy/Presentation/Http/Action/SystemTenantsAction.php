<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\System\SystemTenantAdministrationService;
use Sova\Tenancy\Application\System\SystemTenantInputValidator;
use Sova\Tenancy\Presentation\Http\SystemTenantSerializer;

final readonly class SystemTenantsAction
{
    public function __construct(
        private SystemTenantAdministrationService $administration,
        private SystemTenantInputValidator $validator,
        private SystemTenantSerializer $serializer,
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
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );

        if ($request->getMethod() === 'GET') {
            $this->authorization->require(
                $subject,
                Permission::SystemTenantsView,
                AuthorizationScope::system(),
            );
            $tenants = $this->administration->list();
            $this->audit->record(
                eventType: 'SYSTEM_TENANTS_VIEWED',
                outcome: 'SUCCESS',
                reasonCode: 'SYSTEM_TENANTS_VIEWED',
                requestId: $this->requestId($request),
                actorUserId: $session->actorUserId,
                ipAddress: $this->ipAddress($request),
                metadata: ['result_count' => count($tenants)],
            );

            return JsonResponse::write($response, [
                'tenants' => array_map(
                    $this->serializer->serialize(...),
                    $tenants,
                ),
            ]);
        }

        if ($request->getMethod() === 'POST') {
            $this->authorization->require(
                $subject,
                Permission::SystemTenantsCreate,
                AuthorizationScope::system(),
            );
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $result = $this->administration->create(
                input: $this->validator->validate($payload),
                idempotencyKey: $this->idempotencyKey($request),
                actorUserId: $session->actorUserId,
                requestId: $this->requestId($request),
                ipAddress: $this->ipAddress($request),
            );

            return JsonResponse::write(
                $response,
                [
                    'tenant' => $this->serializer->serialize(
                        $result->tenant,
                    ),
                    'owner_invitation' => [
                        'email' => $result->ownerInvitationEmail,
                        'status' => 'PENDING',
                    ],
                    'replayed' => $result->replayed,
                ],
                $result->replayed ? 200 : 201,
            );
        }

        throw new RuntimeException(
            'Unsupported system tenant collection operation.',
        );
    }

    private function session(ServerRequestInterface $request): SessionContext
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'System tenant administration requires a session context.',
            );
        }

        return $session;
    }

    private function idempotencyKey(
        ServerRequestInterface $request,
    ): string {
        $value = strtolower(trim($request->getHeaderLine('Idempotency-Key')));

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $value,
            ) !== 1
        ) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'IDEMPOTENCY_KEY_INVALID',
                'A valid UUID Idempotency-Key header is required.',
                ['idempotency_key' => ['Provide a valid UUID header value.']],
            );
        }

        return $value;
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
