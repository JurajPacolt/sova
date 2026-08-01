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
use Sova\Issues\Application\Search\QueryTimedOutException;
use Sova\Issues\Presentation\Http\SearchSerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;
use Sova\Tenancy\Application\Access\AccessibleTenant;
use Sova\Tenancy\Infrastructure\Http\Middleware\TenantContextMiddleware;

/**
 * Runs a SovaQL query. It is a `POST` because the query can be long and can
 * contain personal data that must not end up in a URL, a proxy log or browser
 * history — the read is still idempotent (spec §12).
 *
 * The tenant comes from the verified route context; a `tenant_id` in the body
 * would be ignored, and no request field can widen the project scope.
 */
final readonly class SearchIssuesAction
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

        try {
            $outcome = $this->search->search(
                AuthorizationSubject::contextual(
                    $session->actorUserId,
                    $session->userId,
                    $session->actorIsSuperadmin,
                ),
                $tenant->id,
                $this->query($payload['query'] ?? null),
                $this->pageSize($payload['page_size'] ?? null),
                $this->cursor($payload['cursor'] ?? null),
            );
        } catch (QueryTimedOutException $exception) {
            throw new DomainProblemException(
                ProblemType::ServiceUnavailable,
                'QUERY_TIMEOUT',
                'The query exceeded the execution time limit. Narrow it and try again.',
                previous: $exception,
            );
        }

        return JsonResponse::write($response, $this->serializer->serializeOutcome($outcome));
    }

    private function query(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_string($value)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'QUERY_INVALID',
                'The query must be a string.',
                ['query' => ['The query must be a string.']],
            );
        }

        return $value;
    }

    private function pageSize(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,4}$/', $value) === 1) {
            return (int) $value;
        }

        throw new DomainProblemException(
            ProblemType::ValidationFailed,
            'QUERY_INVALID',
            'The page size must be a positive integer.',
            ['page_size' => ['The page size must be a positive integer.']],
        );
    }

    private function cursor(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'QUERY_CURSOR_INVALID',
                'The cursor must be a string.',
            );
        }

        return $value;
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
                'Issue search requires session and tenant contexts.',
            );
        }

        return [$session, $tenant];
    }
}
