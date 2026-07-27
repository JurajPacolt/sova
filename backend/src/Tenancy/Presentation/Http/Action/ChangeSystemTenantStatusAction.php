<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http\Action;

use InvalidArgumentException;
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
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\System\SystemTenantAdministrationService;
use Sova\Tenancy\Application\System\SystemTenantLifecycleValidator;
use Sova\Tenancy\Presentation\Http\SystemTenantSerializer;

final readonly class ChangeSystemTenantStatusAction
{
    public function __construct(
        private SystemTenantAdministrationService $administration,
        private SystemTenantLifecycleValidator $validator,
        private SystemTenantSerializer $serializer,
        private AuthorizationService $authorization,
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
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );

        if (!$session instanceof SessionContext) {
            throw new RuntimeException(
                'System tenant lifecycle requires a session context.',
            );
        }

        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::SystemTenantsManage,
            AuthorizationScope::system(),
        );
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $tenant = $this->administration->changeStatus(
            tenantId: $this->tenantId($args['tenantId'] ?? ''),
            input: $this->validator->validate($payload),
            actorUserId: $session->actorUserId,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
        );

        return JsonResponse::write(
            $response,
            ['tenant' => $this->serializer->serialize($tenant)],
        );
    }

    private function tenantId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'SYSTEM_TENANT_NOT_FOUND',
                'The tenant was not found.',
            );
        }
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
