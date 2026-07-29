<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\IssueService;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Issues\Presentation\Http\IssueSerializer;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;

final readonly class ExecuteIssueTransitionAction
{
    public function __construct(
        private IssueService $issues,
        private IssueRepository $repository,
        private IssueSerializer $serializer,
        private AuthorizationService $authorization,
        private IssueRequestContext $context,
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
        $resolved = $this->context->resolve(
            $request,
            $args['issueId'] ?? '',
            $this->repository,
        );
        $issue = $resolved->issue;
        // Authorize before touching the workflow, otherwise the version and
        // availability errors would tell an outsider what state the issue is in.
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueTransition,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $updated = $this->issues->transition(
            $issue->tenantId,
            $issue->id,
            $this->transitionId($args['transitionId'] ?? ''),
            $this->expectedVersion($payload['expected_issue_version'] ?? null),
            $resolved->session->actorUserId,
            $this->context->transitionPermissionCheck(
                $resolved->subject,
                $issue->tenantId,
                $issue->projectId,
            ),
            $this->context->transitionActor(
                $resolved->subject,
                $issue->tenantId,
                $issue->projectId,
                $resolved->actorMembershipId,
            ),
            $this->suppliedResolution($payload),
        );

        return JsonResponse::write($response, [
            'issue' => $this->serializer->serialize($updated),
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function suppliedResolution(array $payload): ?string
    {
        $fields = $payload['fields'] ?? null;
        $value = is_array($fields) ? ($fields['resolution'] ?? null) : null;
        $value ??= $payload['resolution'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function transitionId(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'TRANSITION_NOT_AVAILABLE',
                'The transition does not belong to the current status and workflow version.',
            );
        }
    }

    private function expectedVersion(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        throw new DomainProblemException(
            ProblemType::ValidationFailed,
            'ISSUE_INPUT_INVALID',
            'Send the issue version the transition was chosen against.',
            ['expected_issue_version' => ['Provide the current issue version.']],
        );
    }
}
