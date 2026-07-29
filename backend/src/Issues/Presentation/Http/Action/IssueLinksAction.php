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
use Sova\Issues\Application\Link\IssueLink;
use Sova\Issues\Application\Link\IssueLinkService;
use Sova\Issues\Application\Search\SearchScopeProvider;
use Sova\Issues\Domain\Link\IssueLinkType;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Lists and creates the links of one issue.
 *
 * Both ends are filtered through the caller's `issue.view` scope, which is the
 * same project set the search uses. Reading a link to an issue outside it would
 * disclose that issue's key and title, so such links are simply absent, and
 * linking to one answers the same `404` as linking to something that does not
 * exist.
 */
final readonly class IssueLinksAction
{
    public function __construct(
        private IssueLinkService $links,
        private IssueRepository $issues,
        private SearchScopeProvider $scopes,
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
            $this->issues,
        );
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        $visible = $this->scopes->scopeFor($resolved->subject, $issue->tenantId)->projectIds;

        if ($request->getMethod() !== 'POST') {
            return JsonResponse::write($response, [
                'links' => array_map(
                    $this->serialize(...),
                    $this->links->listForIssue($issue->tenantId, $issue->id, $visible),
                ),
            ]);
        }

        // Adding a link changes how the issue relates to other work, so it is
        // governed by `issue.edit` on the issue's own project.
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueEdit,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $this->links->create(
            $issue,
            $this->targetId($payload['target_issue_id'] ?? null),
            $this->type($payload['link_type'] ?? null),
            $visible,
            $resolved->session->actorUserId,
        );

        return JsonResponse::write(
            $response,
            [
                'links' => array_map(
                    $this->serialize(...),
                    $this->links->listForIssue($issue->tenantId, $issue->id, $visible),
                ),
            ],
            201,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(IssueLink $link): array
    {
        return [
            'id' => $link->id,
            'type' => $link->type->value,
            // What the link means from this issue's side, so the client never
            // has to work out the direction itself.
            'relation' => $link->relation,
            'outward' => $link->outward,
            'issue' => [
                'id' => $link->otherIssueId,
                'key' => $link->otherIssueKey,
                'title' => $link->otherIssueTitle,
                'project_id' => $link->otherProjectId,
                'status' => [
                    'code' => $link->otherStatusCode,
                    'category' => $link->otherStatusCategory,
                ],
            ],
            'created_at' => $link->createdAt->format(DATE_ATOM),
        ];
    }

    private function targetId(mixed $value): string
    {
        if (!is_string($value)) {
            throw $this->invalid('target_issue_id', 'Provide the issue to link to.');
        }

        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'ISSUE_NOT_FOUND',
                'The issue was not found.',
            );
        }
    }

    private function type(mixed $value): IssueLinkType
    {
        $type = is_string($value) ? IssueLinkType::tryFrom($value) : null;

        if ($type === null) {
            throw $this->invalid(
                'link_type',
                'Use one of: BLOCKS, RELATES_TO, DUPLICATES.',
            );
        }

        return $type;
    }

    private function invalid(string $field, string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'ISSUE_LINK_INVALID',
            $message,
            [$field => [$message]],
        );
    }
}
