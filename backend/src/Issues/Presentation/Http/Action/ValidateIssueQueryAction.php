<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Infrastructure\Http\Middleware\SessionAuthenticationMiddleware;
use Sova\Issues\Application\Search\IssueSearchService;
use Sova\Issues\Presentation\Http\SearchSerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Validates a query without running it. An invalid query is a `200` carrying
 * `valid: false` and the structured errors of spec §4.11 — the request itself
 * succeeded, and the editor needs every error with its range at once rather
 * than a Problem Details body that can only carry codes.
 *
 * References are checked in the caller's scope, so a project code they cannot
 * reach reports the same "not available" as one that does not exist.
 */
final readonly class ValidateIssueQueryAction
{
    public function __construct(
        private IssueSearchService $search,
        private SearchSerializer $serializer,
    ) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        [$session, $tenant] = $this->contexts($request);
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $query = $payload['query'] ?? '';

        if (!is_string($query)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'QUERY_INVALID',
                'The query must be a string.',
                ['query' => ['The query must be a string.']],
            );
        }

        $validation = $this->search->validate(
            AuthorizationSubject::contextual(
                $session->actorUserId,
                $session->userId,
                $session->actorIsSuperadmin,
            ),
            $tenant->id,
            $query,
        );

        return JsonResponse::write(
            $response,
            $this->serializer->serializeValidation($validation),
        );
    }

    /**
     * @return array{SessionContext, AccessibleTenant}
     */
    private function contexts(ServerRequestInterface $request): array
    {
        $session = $request->getAttribute(SessionAuthenticationMiddleware::ATTRIBUTE);
        $tenant = $request->getAttribute(TenantContextMiddleware::ATTRIBUTE);

        if (!$session instanceof SessionContext || !$tenant instanceof AccessibleTenant) {
            throw new RuntimeException(
                'Issue query validation requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }
}
