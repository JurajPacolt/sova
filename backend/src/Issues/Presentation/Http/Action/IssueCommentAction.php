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
use Sova\Issues\Application\Comment\CommentRecord;
use Sova\Issues\Application\Comment\CommentRepository;
use Sova\Issues\Application\Comment\CommentService;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Presentation\Http\ActivitySerializer;
use Sova\Issues\Presentation\Http\IssueRequestContext;
use Sova\Issues\Presentation\Http\ResolvedIssue;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Presentation\Http\JsonResponse;

/**
 * Edits and removes a single comment. The comment is always loaded through the
 * issue of the route, so an identifier belonging to another issue or tenant
 * reads as missing rather than confirming that it exists somewhere else.
 */
final readonly class IssueCommentAction
{
    public function __construct(
        private CommentService $comments,
        private CommentRepository $repository,
        private IssueRepository $issues,
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
            $this->issues,
        );
        $comment = $this->comment($resolved, $args['commentId'] ?? '');

        // Reading the issue is the floor for touching its discussion; the
        // service then decides authorship, the edit window and moderation.
        $this->authorization->require(
            $resolved->subject,
            Permission::IssueView,
            AuthorizationScope::project(
                $resolved->issue->tenantId,
                $resolved->issue->projectId,
            ),
        );

        if ($request->getMethod() === 'DELETE') {
            $this->comments->delete(
                $resolved->subject,
                $comment,
                $resolved->issue->version,
                $resolved->session->actorUserId,
            );

            return $response->withStatus(204);
        }

        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];

        $this->comments->edit(
            $resolved->subject,
            $comment,
            $resolved->issue->version,
            $this->body($payload['body'] ?? null),
            $resolved->session->actorUserId,
        );

        $updated = null;

        foreach ($this->comments->listForIssue(
            $resolved->issue->tenantId,
            $resolved->issue->id,
        ) as $entry) {
            if ($entry->id === $comment->id) {
                $updated = $entry;
            }
        }

        return JsonResponse::write(
            $response,
            ['comment' => $updated === null
                ? ['id' => $comment->id]
                : $this->serializer->serializeComment($updated)],
        );
    }

    private function comment(ResolvedIssue $resolved, string $commentId): CommentRecord
    {
        try {
            $identifier = (string) UuidV7::fromString($commentId);
        } catch (InvalidArgumentException) {
            throw $this->notFound();
        }

        $comment = $this->repository->find($resolved->issue->tenantId, $identifier);

        if ($comment === null || $comment->issueId !== $resolved->issue->id) {
            throw $this->notFound();
        }

        return $comment;
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

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'COMMENT_NOT_FOUND',
            'The comment was not found.',
        );
    }
}
