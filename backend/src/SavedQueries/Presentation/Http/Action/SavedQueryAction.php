<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Presentation\Http\Action;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\SavedQueries\Application\SavedQueryService;
use Sova\SavedQueries\Presentation\Http\SavedQueryContext;
use Sova\SavedQueries\Presentation\Http\SavedQuerySerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Reads and edits one saved query. Editing carries the version the caller saw,
 * so a concurrent change is reported instead of silently overwritten.
 */
final readonly class SavedQueryAction
{
    public function __construct(
        private SavedQueryService $queries,
        private SavedQuerySerializer $serializer,
        private SavedQueryContext $context,
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
        [, $tenant, $subject, $membershipId] = $this->context->resolve($request);
        $savedQueryId = $this->identifier($args['savedQueryId'] ?? '');

        if ($request->getMethod() === 'PATCH') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            $this->queries->update(
                $subject,
                $tenant->id,
                $savedQueryId,
                $membershipId,
                $this->version($payload['expected_version'] ?? null),
                $this->text($payload['name'] ?? null, 'name'),
                is_string($payload['description'] ?? null)
                    ? mb_substr($payload['description'], 0, 500)
                    : '',
                $this->text($payload['query'] ?? null, 'query'),
                $this->columns($payload['default_columns'] ?? null),
            );
        }

        return JsonResponse::write($response, [
            'saved_query' => $this->serializer->serialize(
                $this->queries->get($subject, $tenant->id, $savedQueryId, $membershipId),
            ),
        ]);
    }

    private function identifier(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'SAVED_QUERY_NOT_FOUND',
                'The saved query was not found.',
            );
        }
    }

    private function version(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        throw new DomainProblemException(
            ProblemType::ValidationFailed,
            'SAVED_QUERY_INVALID',
            'Send the version the edit was made against.',
            ['expected_version' => ['Send the version the edit was made against.']],
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
