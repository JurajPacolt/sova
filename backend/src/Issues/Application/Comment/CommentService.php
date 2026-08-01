<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Comment;

use DateTimeImmutable;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\IssueRepository;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Issues\Application\Watcher\WatchSource;
use Sova\Issues\Domain\Comment\CommentBodyValidator;
use Sova\Issues\Domain\Comment\MentionExtractor;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Throwable;

/**
 * Creating, editing and removing issue comments.
 *
 * Two rules drive the design. A comment stores CommonMark **source** and never
 * rendered markup, and a mention is re-authorised on every write: the mentioned
 * member must be active in this tenant and must already hold `issue.view` on
 * the project. Mentioning someone who cannot see the issue is refused outright,
 * the MVP variant the webflow recommends — storing it silently would leave a
 * notification that either leaks the issue or never arrives.
 *
 * The permission check for a mentioned member deliberately ignores the
 * `SUPERADMIN` bypass. System power is for explicit, audited access to tenant
 * content, not a side channel that quietly turns a mention into a notification.
 */
final readonly class CommentService
{
    private int $editWindowSeconds;

    public function __construct(
        private CommentRepository $comments,
        private IssueRepository $issues,
        private CommentEventPublisher $events,
        private AuthorizationService $authorization,
        private CommentBodyValidator $bodyValidator,
        private MentionExtractor $mentions,
        private WatcherRepository $watchers,
        Settings $settings,
    ) {
        $window = $settings->int('comments.edit_window_seconds', 900);
        $this->editWindowSeconds = $window > 0 ? $window : 900;
    }

    /**
     * @return list<CommentDetails>
     */
    public function listForIssue(string $tenantId, string $issueId): array
    {
        return $this->comments->listForIssue($tenantId, $issueId);
    }

    public function create(
        string $tenantId,
        string $projectId,
        string $issueId,
        int $issueVersion,
        string $authorMembershipId,
        string $body,
        string $actorUserId,
    ): string {
        $this->assertBody($body);
        $mentioned = $this->resolveMentions($tenantId, $projectId, $body);
        $commentId = (string) UuidV7::generate();

        $this->comments->beginTransaction();

        try {
            $this->comments->create(
                $tenantId,
                $projectId,
                $issueId,
                $commentId,
                $authorMembershipId,
                $body,
                $mentioned,
            );
            $this->record(
                $tenantId,
                $projectId,
                $issueId,
                $issueVersion,
                $commentId,
                'COMMENT_ADDED',
                $actorUserId,
                1,
                $mentioned,
            );
            // Joining the discussion subscribes the author, unless they had
            // already decided otherwise.
            $this->watchers->watchAutomatically(
                $tenantId,
                $projectId,
                $issueId,
                $authorMembershipId,
                WatchSource::Comment,
            );
            $this->comments->commit();
        } catch (Throwable $exception) {
            $this->comments->rollBack();

            throw $exception;
        }

        return $commentId;
    }

    public function edit(
        AuthorizationSubject $subject,
        CommentRecord $comment,
        int $issueVersion,
        string $body,
        string $actorUserId,
    ): void {
        $this->assertEditable($subject, $comment, $actorUserId);
        $this->assertBody($body);
        $mentioned = $this->resolveMentions($comment->tenantId, $comment->projectId, $body);

        $this->comments->beginTransaction();

        try {
            $version = $this->comments->update(
                $comment->tenantId,
                $comment->id,
                $body,
                $mentioned,
            );
            $this->record(
                $comment->tenantId,
                $comment->projectId,
                $comment->issueId,
                $issueVersion,
                $comment->id,
                'COMMENT_EDITED',
                $actorUserId,
                $version,
                $mentioned,
            );
            $this->comments->commit();
        } catch (Throwable $exception) {
            $this->comments->rollBack();

            throw $exception;
        }
    }

    public function delete(
        AuthorizationSubject $subject,
        CommentRecord $comment,
        int $issueVersion,
        string $actorUserId,
    ): void {
        if ($comment->deleted) {
            // Repeating the removal is a no-op rather than an error, so a
            // retried request cannot fail after the first one succeeded.
            return;
        }

        $this->assertDeletable($subject, $comment, $actorUserId);

        $this->comments->beginTransaction();

        try {
            $this->comments->softDelete($comment->tenantId, $comment->id, $actorUserId);
            $this->record(
                $comment->tenantId,
                $comment->projectId,
                $comment->issueId,
                $issueVersion,
                $comment->id,
                'COMMENT_DELETED',
                $actorUserId,
                $comment->version + 1,
                [],
            );
            $this->comments->commit();
        } catch (Throwable $exception) {
            $this->comments->rollBack();

            throw $exception;
        }
    }

    /**
     * The author gets a grace window on their own comment; beyond it, and for
     * anyone else's comment, `comment.moderate` is required.
     */
    private function assertEditable(
        AuthorizationSubject $subject,
        CommentRecord $comment,
        string $actorUserId,
    ): void {
        if ($comment->deleted) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'COMMENT_DELETED',
                'A removed comment can no longer be edited.',
            );
        }

        if ($this->canModerate($subject, $comment)) {
            return;
        }

        if ($comment->authorUserId !== $actorUserId) {
            throw $this->denied();
        }

        if ($this->windowExpired($comment)) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'COMMENT_EDIT_WINDOW_CLOSED',
                'The time window for editing this comment has passed.',
            );
        }
    }

    private function assertDeletable(
        AuthorizationSubject $subject,
        CommentRecord $comment,
        string $actorUserId,
    ): void {
        if ($this->canModerate($subject, $comment)) {
            return;
        }

        if ($comment->authorUserId !== $actorUserId) {
            throw $this->denied();
        }
    }

    private function canModerate(
        AuthorizationSubject $subject,
        CommentRecord $comment,
    ): bool {
        return $this->authorization->isGranted(
            $subject,
            Permission::CommentModerate,
            AuthorizationScope::project($comment->tenantId, $comment->projectId),
        );
    }

    private function windowExpired(CommentRecord $comment): bool
    {
        $deadline = $comment->createdAt->getTimestamp() + $this->editWindowSeconds;

        return (new DateTimeImmutable())->getTimestamp() > $deadline;
    }

    /**
     * @return list<string>
     */
    private function resolveMentions(
        string $tenantId,
        string $projectId,
        string $body,
    ): array {
        $requested = $this->mentions->extract($body);

        if ($requested === []) {
            return [];
        }

        $users = $this->comments->activeMembershipUsers($tenantId, $requested);
        $scope = AuthorizationScope::project($tenantId, $projectId);
        $allowed = [];

        foreach ($requested as $membershipId) {
            $userId = $users[$membershipId] ?? null;

            if ($userId === null || !$this->authorization->isGranted(
                AuthorizationSubject::authenticated($userId, false),
                Permission::IssueView,
                $scope,
            )) {
                throw new DomainProblemException(
                    ProblemType::ValidationFailed,
                    'COMMENT_MENTION_NOT_ALLOWED',
                    'A mentioned member does not have access to this issue.',
                    ['body' => ['A mentioned member does not have access to this issue.']],
                );
            }

            $allowed[] = $membershipId;
        }

        return $allowed;
    }

    private function assertBody(string $body): void
    {
        $violations = $this->bodyValidator->violations($body);

        if ($violations === []) {
            return;
        }

        throw new DomainProblemException(
            ProblemType::ValidationFailed,
            'COMMENT_BODY_INVALID',
            'The comment body was rejected.',
            ['body' => $violations],
        );
    }

    /**
     * @param list<string> $mentioned
     */
    private function record(
        string $tenantId,
        string $projectId,
        string $issueId,
        int $issueVersion,
        string $commentId,
        string $eventType,
        string $actorUserId,
        int $sequence,
        array $mentioned,
    ): void {
        $this->issues->recordHistory(
            $tenantId,
            $projectId,
            $issueId,
            $issueVersion,
            $eventType,
            $actorUserId,
            null,
            null,
            null,
            ['comment_id' => $commentId],
            // A comment annotates the issue; it does not change it, so it must
            // neither bump the version nor claim the one-entry-per-version slot.
            false,
        );

        $this->events->publish($tenantId, $commentId, $sequence, $eventType, [
            'issue_id' => $issueId,
            'project_id' => $projectId,
            'comment_id' => $commentId,
            'actor_user_id' => $actorUserId,
            'mentioned_membership_ids' => $mentioned,
        ]);
    }

    private function denied(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::PermissionDenied,
            'PERMISSION_DENIED',
            'You do not have permission to perform this operation.',
        );
    }
}
