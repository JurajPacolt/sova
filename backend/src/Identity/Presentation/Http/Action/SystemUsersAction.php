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
use Sova\Identity\Application\System\SystemUserAdministrationService;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Presentation\Http\SystemUserSerializer;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class SystemUsersAction
{
    public function __construct(
        private SystemUserAdministrationService $administration,
        private SystemUserSerializer $serializer,
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
        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::SystemUsersManage,
            AuthorizationScope::system(),
        );
        $users = $this->administration->list();
        $this->audit->record(
            eventType: 'SYSTEM_USERS_VIEWED',
            outcome: 'SUCCESS',
            reasonCode: 'SYSTEM_USERS_VIEWED',
            requestId: $this->requestId($request),
            actorUserId: $session->actorUserId,
            ipAddress: $this->ipAddress($request),
            metadata: ['result_count' => count($users)],
        );

        return JsonResponse::write($response, [
            'users' => array_map($this->serializer->serialize(...), $users),
        ]);
    }

    private function session(ServerRequestInterface $request): SessionContext
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'System user administration requires a session context.',
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
