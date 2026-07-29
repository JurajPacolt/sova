<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Presentation\Http\Action;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\SavedQueries\Application\SavedQueryService;
use Sova\SavedQueries\Presentation\Http\SavedQueryContext;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Marks a query as the caller's favourite, or removes the mark. It is a
 * personal bookmark keyed on the membership, not a property of the query, so it
 * needs nothing beyond being able to see it. Both verbs are idempotent.
 */
final readonly class SavedQueryFavouriteAction
{
    public function __construct(
        private SavedQueryService $queries,
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
        $favourite = $request->getMethod() === 'PUT';

        $this->queries->setFavourite(
            $subject,
            $tenant->id,
            $this->identifier($args['savedQueryId'] ?? ''),
            $membershipId,
            $favourite,
        );

        return JsonResponse::write($response, ['favourite' => $favourite]);
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
}
