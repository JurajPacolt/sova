<?php

declare(strict_types=1);

namespace Sova\Projects\Presentation\Http\Action;

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
use Sova\Projects\Application\CreateProjectInputValidator;
use Sova\Projects\Application\ProjectAdministrationService;
use Sova\Projects\Presentation\Http\ProjectSerializer;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

final readonly class ProjectsAction
{
    public function __construct(
        private ProjectAdministrationService $administration,
        private CreateProjectInputValidator $validator,
        private ProjectSerializer $serializer,
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
        $subject = AuthorizationSubject::contextual(
            $session->actorUserId,
            $session->userId,
            $session->actorIsSuperadmin,
        );

        if ($request->getMethod() === 'GET') {
            $this->authorization->require(
                $subject,
                Permission::TenantProjectsManage,
                AuthorizationScope::tenant($tenant->id),
            );
            $projects = $this->administration->list($tenant->id);

            return JsonResponse::write($response, [
                'projects' => array_map(
                    $this->serializer->serialize(...),
                    $projects,
                ),
            ]);
        }

        if ($request->getMethod() === 'POST') {
            $this->authorization->require(
                $subject,
                Permission::TenantProjectsCreate,
                AuthorizationScope::tenant($tenant->id),
            );
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $project = $this->administration->create(
                $tenant->id,
                $this->validator->validate($payload),
                $session->actorUserId,
                $this->requestId($request),
                $this->ipAddress($request),
            );

            return JsonResponse::write(
                $response,
                ['project' => $this->serializer->serialize($project)],
                201,
            );
        }

        throw new RuntimeException('Unsupported project collection operation.');
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
                'Project administration requires session and tenant contexts.',
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
