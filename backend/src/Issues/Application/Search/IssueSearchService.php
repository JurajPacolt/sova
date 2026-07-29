<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Issues\Domain\QueryLanguage\BasicFormProjector;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\QueryError;
use Sova\Issues\Domain\QueryLanguage\QueryErrorCode;
use Sova\Issues\Domain\QueryLanguage\QueryLimits;
use Sova\Issues\Domain\QueryLanguage\SovaQlAnalyzer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

/**
 * The single entry point for running SovaQL.
 *
 * The order of the steps is the security contract of spec §13 and must not be
 * rearranged: budget, then static analysis, then the authorised scope, then
 * reference resolution *inside* that scope, then compilation, and only then the
 * database. Nothing reaches PostgreSQL before the query has been validated, and
 * no filtering is left to PHP after the rows come back.
 */
final readonly class IssueSearchService
{
    public function __construct(
        private SovaQlAnalyzer $analyzer,
        private FieldCatalog $fields,
        private QueryCompiler $compiler,
        private SearchScopeProvider $scopes,
        private ReferenceResolver $references,
        private IssueSearchRepository $repository,
        private QueryRateLimiter $rateLimiter,
        private CursorCodec $cursors,
        private QueryLimits $limits,
        private BasicFormProjector $basicForm,
    ) {}

    public function validate(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
    ): IssueQueryValidation {
        $this->requireAllowance($subject, $tenantId);
        $analyzed = $this->analyzer->analyze($query);

        if (!$analyzed->valid || $analyzed->ast === null) {
            return new IssueQueryValidation(false, $analyzed->errors, null);
        }

        $basicForm = $this->basicForm->project($analyzed->ast);

        $scope = $this->scopes->scopeFor($subject, $tenantId);
        $compiled = $this->compiler->compile(
            $analyzed->ast,
            $this->references->resolve(
                $scope,
                ReferenceRequest::collect($analyzed->ast, $this->fields),
            ),
        );

        return new IssueQueryValidation(
            $compiled->isValid(),
            $compiled->errors,
            $compiled->isValid() ? $analyzed->canonical : null,
            $basicForm,
        );
    }

    /**
     * Runs every step up to, but not including, the database: budget, static
     * analysis, authorised scope, reference resolution *inside* that scope,
     * compilation. Aggregation shares it, so the order lives in one place.
     */
    public function plan(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
    ): QueryPlan {
        $this->requireAllowance($subject, $tenantId);
        $analyzed = $this->analyzer->analyze($query);

        if (!$analyzed->valid || $analyzed->ast === null || $analyzed->canonical === null) {
            throw $this->queryProblem($analyzed->errors);
        }

        $scope = $this->scopes->scopeFor($subject, $tenantId);
        $compiled = $this->compiler->compile(
            $analyzed->ast,
            $this->references->resolve(
                $scope,
                ReferenceRequest::collect($analyzed->ast, $this->fields),
            ),
        );

        if (!$compiled->isValid()) {
            throw $this->queryProblem($compiled->errors);
        }

        return new QueryPlan($scope, $compiled, $analyzed->canonical);
    }

    public function search(
        AuthorizationSubject $subject,
        string $tenantId,
        string $query,
        ?int $pageSize,
        ?string $cursorToken,
    ): SearchOutcome {
        $limit = $this->pageSize($pageSize);
        $plan = $this->plan($subject, $tenantId, $query);
        $scope = $plan->scope;
        $compiled = $plan->compiled;

        $binding = new CursorBinding(
            $scope->tenantId,
            $scope->effectiveUserId,
            $scope->authorizationRevision,
            $plan->canonical,
            $compiled->sort,
        );

        $cursor = null;

        if ($cursorToken !== null && $cursorToken !== '') {
            $cursor = $this->cursors->decode($cursorToken, $binding);

            if ($cursor === null) {
                throw new DomainProblemException(
                    ProblemType::ValidationFailed,
                    'QUERY_CURSOR_INVALID',
                    'The cursor does not belong to this query, sort order or context.',
                );
            }
        }

        // One extra row is the cheapest way to learn whether another page
        // exists without a second count query.
        $rows = $this->repository->search($scope, $compiled, $cursor, $limit + 1);
        $hasMore = count($rows) > $limit;
        $page = $hasMore ? array_slice($rows, 0, $limit) : $rows;

        return new SearchOutcome(
            array_map(static fn(SearchRow $row): SearchResultItem => $row->item, $page),
            $hasMore ? $this->nextCursor($page, $binding) : null,
            $plan->canonical,
            $limit,
        );
    }

    /**
     * @param list<SearchRow> $page
     */
    private function nextCursor(array $page, CursorBinding $binding): ?string
    {
        $last = $page[count($page) - 1] ?? null;

        if ($last === null) {
            return null;
        }

        return $this->cursors->encode(
            new SearchCursor($last->sortValues, $last->item->id),
            $binding,
        );
    }

    private function pageSize(?int $requested): int
    {
        if ($requested === null || $requested < 1) {
            return $this->limits->defaultPageSize;
        }

        return min($requested, $this->limits->maxPageSize);
    }

    private function requireAllowance(AuthorizationSubject $subject, string $tenantId): void
    {
        if ($this->rateLimiter->consumeAllowance($tenantId, $subject->effectiveUserId)) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::RateLimitExceeded,
            'QUERY_RATE_LIMITED',
            'Too many queries were run in a short time. Try again shortly.',
        );
    }

    /**
     * Search answers with Problem Details, so the structured ranges of §4.11 do
     * not fit; the distinct stable codes are reported instead and the editor
     * calls the validate endpoint for the exact positions.
     *
     * @param list<QueryError> $errors
     */
    private function queryProblem(array $errors): DomainProblemException
    {
        $codes = [];

        foreach ($errors as $error) {
            $codes[$error->code->value] = true;
        }

        $distinct = array_keys($codes);
        $first = $errors[0] ?? null;
        $single = count($distinct) === 1 && $first !== null ? $first->code : null;

        $problemCode = match ($single) {
            QueryErrorCode::TooLong => 'QUERY_TOO_LONG',
            QueryErrorCode::TooComplex => 'QUERY_TOO_COMPLEX',
            default => 'QUERY_INVALID',
        };

        return new DomainProblemException(
            ProblemType::ValidationFailed,
            $problemCode,
            'The query could not be executed as written.',
            ['query' => $distinct === [] ? ['QUERY_INVALID'] : $distinct],
        );
    }
}
