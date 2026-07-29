<?php

declare(strict_types=1);

namespace Sova\Issues\Presentation\Http\Action;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\Comment\CommentDetails;
use Sova\Issues\Application\Comment\CommentService;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Presentation\Http\ActivitySerializer;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Issues\Presentation\Http\ResolvedIssue;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Lists and creates the comments of one issue. Reading needs `issue.view` and
 * writing needs `comment.create`, both on the issue's project — a tenant role
 * never substitutes for either.
 */
final readonly class IssueCommentsAction
{
    public function __construct(
        private CommentService $comments,
        private IssueRepository $repository,
        private ActivitySerializer $serializer,
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

        return $request->getMethod() === 'POST'
            ? $this->create($request, $response, $resolved)
            : $this->list($response, $resolved);
    }

    /**
     * @throws JsonException
     */
    private function list(
        ResponseInterface $response,
        ResolvedIssue $resolved,
    ): ResponseInterface {
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        return JsonResponse::write($response, [
            'comments' => array_map(
                fn(CommentDetails $comment): array => $this->serializer
                    ->serializeComment($comment),
                $this->comments->listForIssue($issue->tenantId, $issue->id),
            ),
        ]);
    }

    /**
     * @throws JsonException
     */
    private function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ResolvedIssue $resolved,
    ): ResponseInterface {
        $issue = $resolved->issue;
        $this->authorization->require(
            $resolved->subject,
            Permission::CommentCreate,
            AuthorizationScope::project($issue->tenantId, $issue->projectId),
        );

        if ($resolved->actorMembershipId === null) {
            // A comment is authored by a tenant member; a caller acting purely
            // on system power has no membership to attribute it to.
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'COMMENT_AUTHOR_REQUIRED',
                'Only a tenant member can comment on an issue.',
            );
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $commentId = $this->comments->create(
            $issue->tenantId,
            $issue->projectId,
            $issue->id,
            $issue->version,
            $resolved->actorMembershipId,
            $this->body($payload['body'] ?? null),
            $resolved->session->actorUserId,
        );

        $created = null;

        foreach ($this->comments->listForIssue($issue->tenantId, $issue->id) as $comment) {
            if ($comment->id === $commentId) {
                $created = $comment;
            }
        }

        return JsonResponse::write(
            $response,
            ['comment' => $created === null
                ? ['id' => $commentId]
                : $this->serializer->serializeComment($created)],
            201,
        );
    }

    private function body(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'COMMENT_BODY_INVALID',
                'The comment body must be a string.',
                ['body' => ['The comment body must be a string.']],
            );
        }

        return $value;
    }
}
