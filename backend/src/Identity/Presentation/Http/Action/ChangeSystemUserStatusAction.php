<?php

declare(strict_types=1);

namespace Sova\Identity\Presentation\Http\Action;

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
use Sova\Identity\Application\System\SystemUserAdministrationService;
use Sova\Identity\Application\System\SystemUserStatusValidator;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Identity\Presentation\Http\SystemUserSerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Http\Middleware\RequestIdMiddleware;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class ChangeSystemUserStatusAction
{
    public function __construct(
        private SystemUserAdministrationService $administration,
        private SystemUserStatusValidator $validator,
        private SystemUserSerializer $serializer,
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
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $user = $this->administration->changeStatus(
            userId: $this->userId($args['userId'] ?? ''),
            targetStatus: $this->validator->validate($payload),
            actorUserId: $session->actorUserId,
            requestId: $this->requestId($request),
            ipAddress: $this->ipAddress($request),
        );

        return JsonResponse::write(
            $response,
            ['user' => $this->serializer->serialize($user)],
        );
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

    private function userId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'SYSTEM_USER_NOT_FOUND',
                'The system user was not found.',
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
