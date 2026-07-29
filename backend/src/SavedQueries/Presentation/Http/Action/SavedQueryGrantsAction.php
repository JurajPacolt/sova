<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Presentation\Http\Action;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\SavedQueries\Application\SavedQueryGrant;
use Sova\SavedQueries\Application\SavedQueryService;
use Sova\SavedQueries\Presentation\Http\SavedQueryContext;
use Sova\SavedQueries\Presentation\Http\SavedQuerySerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Reads and replaces the grants of a saved query.
 *
 * `PUT` replaces the whole set rather than patching it, so a principal removed
 * from the list really loses access — a partial update could leave a stale
 * grant behind. Sharing never conveys access to the issues the query returns.
 */
final readonly class SavedQueryGrantsAction
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

        if ($request->getMethod() === 'PUT') {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            $this->queries->replaceGrants(
                $subject,
                $tenant->id,
                $savedQueryId,
                $membershipId,
                $this->grants($payload['grants'] ?? null),
                $session->actorUserId,
            );
        }

        return JsonResponse::write($response, [
            'grants' => array_map(
                fn(SavedQueryGrant $grant): array => $this->serializer->serializeGrant($grant),
                $this->queries->grants($subject, $tenant->id, $savedQueryId, $membershipId),
            ),
        ]);
    }

    /**
     * @return list<array{membership_id: ?string, workgroup_id: ?string, access: string}>
     */
    private function grants(mixed $value): array
    {
        if (!is_array($value)) {
            throw $this->invalid();
        }

        $grants = [];

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw $this->invalid();
            }

            $membershipId = $entry['membership_id'] ?? null;
            $workgroupId = $entry['workgroup_id'] ?? null;
            $access = $entry['access'] ?? null;

            if (!is_string($access)) {
                throw $this->invalid();
            }

            $grants[] = [
                'membership_id' => is_string($membershipId) ? $membershipId : null,
                'workgroup_id' => is_string($workgroupId) ? $workgroupId : null,
                'access' => $access,
            ];

            if (count($grants) > 200) {
                throw $this->invalid();
            }
        }

        return $grants;
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

    private function invalid(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'SAVED_QUERY_GRANT_INVALID',
            'A grant must name one active member or workgroup of this tenant.',
            ['grants' => ['A grant must name one active member or workgroup of this tenant.']],
        );
    }
}
