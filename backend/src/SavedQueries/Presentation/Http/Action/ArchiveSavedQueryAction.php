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
 * Archives a query. Only its owner or a tenant administrator may retire it —
 * holding `EDIT` is enough to change a query, never to take it out of everyone
 * else's list. Repeating the call is a no-op.
 */
final readonly class ArchiveSavedQueryAction
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
        [$session, $tenant, $subject, $membershipId] = $this->context->resolve($request);
        $savedQueryId = $this->identifier($args['savedQueryId'] ?? '');
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $this->queries->archive(
            $subject,
            $tenant->id,
            $savedQueryId,
            $membershipId,
            $this->version($payload['expected_version'] ?? null),
            $session->actorUserId,
            $this->context->requestId($request),
            $this->context->ipAddress($request),
        );

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
            'Send the version the archive was chosen against.',
            ['expected_version' => ['Send the version the archive was chosen against.']],
        );
    }
}
