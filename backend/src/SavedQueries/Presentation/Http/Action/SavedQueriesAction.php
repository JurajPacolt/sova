<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\SavedQueries\Application\SavedQuery;
use Sova\SavedQueries\Application\SavedQueryService;
use Sova\SavedQueries\Presentation\Http\SavedQueryContext;
use Sova\SavedQueries\Presentation\Http\SavedQuerySerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Lists the queries the caller may reach and saves new ones. A new query is
 * always private; sharing is a separate, explicit act.
 */
final readonly class SavedQueriesAction
{
    public function __construct(
        private SavedQueryService $queries,
        private SavedQuerySerializer $serializer,
        private SavedQueryContext $context,
    ) {}

    /**
     * @throws JsonException
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        [, $tenant, $subject, $membershipId] = $this->context->resolve($request);

        if ($request->getMethod() !== 'POST') {
            return JsonResponse::write($response, [
                'saved_queries' => array_map(
                    fn(SavedQuery $query): array => $this->serializer->serialize($query),
                    $this->queries->listVisible($subject, $tenant->id, $membershipId),
                ),
            ]);
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $savedQueryId = $this->queries->create(
            $subject,
            $tenant->id,
            $membershipId,
            $this->text($payload['name'] ?? null, 'name'),
            $this->optionalText($payload['description'] ?? null),
            $this->text($payload['query'] ?? null, 'query'),
            $this->columns($payload['default_columns'] ?? null),
        );

        return JsonResponse::write(
            $response,
            [
                'saved_query' => $this->serializer->serialize(
                    $this->queries->get($subject, $tenant->id, $savedQueryId, $membershipId),
                ),
            ],
            201,
        );
    }

    private function text(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'SAVED_QUERY_INVALID',
                sprintf('Provide a %s.', $field),
                [$field => [sprintf('Provide a %s.', $field)]],
            );
        }

        return $value;
    }

    private function optionalText(mixed $value): string
    {
        return is_string($value) ? mb_substr($value, 0, 500) : '';
    }

    /**
     * @return list<string>
     */
    private function columns(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $columns = [];

        foreach ($value as $column) {
            if (is_string($column) && $column !== '') {
                $columns[] = $column;
            }
        }

        return array_slice($columns, 0, 20);
    }
}
