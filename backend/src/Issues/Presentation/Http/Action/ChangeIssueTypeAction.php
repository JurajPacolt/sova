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

final readonly class ChangeIssueTypeAction
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
        // Authorize before validating the body so an outsider cannot learn the
        // issue's version or type from the shape of the error.
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueChangeType,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $updated = $this->issues->changeType(
            $issue->tenantId,
            $issue->id,
            $this->targetIssueTypeId($payload['target_issue_type_id'] ?? null),
            $this->expectedVersion($payload['expected_issue_version'] ?? null),
            $this->targetStatusId($payload['target_status_id'] ?? null),
            $resolved->session->actorUserId,
        );

        return JsonResponse::write($response, [
            'issue' => $this->serializer->serialize($updated),
        ]);
    }

    private function targetIssueTypeId(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ISSUE_TYPE_INVALID',
                'The target issue type is not an active type with a published workflow.',
                ['target_issue_type_id' => ['Choose an active issue type.']],
            );
        }

        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ISSUE_TYPE_INVALID',
                'The target issue type is not an active type with a published workflow.',
                ['target_issue_type_id' => ['Choose an active issue type.']],
            );
        }
    }

    private function targetStatusId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->invalidTargetStatus();
        }

        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw $this->invalidTargetStatus();
        }
    }

    private function invalidTargetStatus(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'ISSUE_TYPE_STATUS_INVALID',
            'The target status does not belong to the target workflow version.',
            ['target_status_id' => ['Choose a status of the target workflow.']],
        );
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
            'Send the issue version the type change was chosen against.',
            ['expected_issue_version' => ['Provide the current issue version.']],
        );
    }
}
