<?php

declare(strict_types=1);

namespace Sova\Workgroups\Presentation\Http\Action;

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
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;
use Sova\Workgroups\Application\CreateWorkgroupInputValidator;
use Sova\Workgroups\Application\WorkgroupAdministrationService;
use Sova\Workgroups\Presentation\Http\WorkgroupSerializer;

final readonly class WorkgroupsAction
{
    public function __construct(
        private WorkgroupAdministrationService $administration,
        private CreateWorkgroupInputValidator $validator,
        private WorkgroupSerializer $serializer,
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
        [$session, $tenant] = $this->contexts($request);
        $this->authorization->require(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            Permission::TenantWorkgroupsManage,
            AuthorizationScope::tenant($tenant->id),
        );

        if ($request->getMethod() === 'GET') {
            $workgroups = $this->administration->list($tenant->id);

            return JsonResponse::write($response, [
                'workgroups' => array_map(
                    $this->serializer->serialize(...),
                    $workgroups,
                ),
            ]);
        }

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $workgroup = $this->administration->create(
                $tenant->id,
                $this->validator->validate($payload),
                $session->actorUserId,
                $this->requestId($request),
                $this->ipAddress($request),
            );

            return JsonResponse::write(
                $response,
                ['workgroup' => $this->serializer->serialize($workgroup)],
                201,
            );
        }

        throw new RuntimeException('Unsupported workgroup collection operation.');
    }

    /**
     * @return array{SessionContext, AccessibleTenant}
     */
    private function contexts(ServerRequestInterface $request): array
    {
        $session = $request->getAttribute(
            SessionAuthenticationMiddleware::ATTRIBUTE,
        );
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (
            !$session instanceof SessionContext
            || !$tenant instanceof AccessibleTenant
        ) {
            throw new RuntimeException(
                'Workgroup administration requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
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
