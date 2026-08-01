<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\IssueDetails;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Notifications\Domain\NotificationKind;
use Sova\Shared\Application\Outbox\OutboxEvent;

/**
 * Works out who an event should reach, once, for every channel.
 *
 * Both the in-app and the e-mail handler use this, which is the point: two
 * copies of the audience rules would drift, and the one that drifted would be
 * the one that mails an issue title to somebody who should not have it.
 *
 * The `issue.view` re-check at the end is not redundant. Watching survives
 * losing access to a project — the row stays — so a member removed from a
 * project between the event and its delivery would otherwise still be told the
 * issue's key and title. Access is therefore confirmed at delivery time, not
 * assumed from the fact that somebody once subscribed.
 */
final readonly class NotificationAudience
{
    public function __construct(
        private WatcherRepository $watchers,
        private MemberDirectory $members,
        private AuthorizationService $authorization,
    ) {}

    /**
     * @return list<Recipient>
     */
    public function resolve(OutboxEvent $event, IssueDetails $issue): array
    {
        $actorUserId = $event->string('actor_user_id');
        $candidates = [];

        if ($event->eventName === 'ISSUE_CREATED') {
            // Creation reaches the person it was handed to and nobody else; the
            // watcher set is resolved too late to be meaningful here.
            $assignee = $event->string('assignee_membership_id');

            if ($assignee !== null && $assignee !== $event->string('reporter_membership_id')) {
                $candidates[$assignee] = NotificationKind::Assigned;
            }
        } else {
            foreach ($event->stringList('mentioned_membership_ids') as $membershipId) {
                $candidates[$membershipId] = NotificationKind::Mentioned;
            }

            $kind = $event->eventName === 'COMMENT_ADDED'
                ? NotificationKind::Commented
                : NotificationKind::Transitioned;

            foreach (
                $this->watchers->watchingMembershipIds($issue->tenantId, $issue->id, $actorUserId) as $membershipId
            ) {
                // A mention already covers this person, and covers them better.
                $candidates[$membershipId] ??= $kind;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $users = $this->members->usersFor($issue->tenantId, array_keys($candidates));
        $scope = AuthorizationScope::project($issue->tenantId, $issue->projectId);
        $recipients = [];

        foreach ($candidates as $membershipId => $kind) {
            $userId = $users[$membershipId] ?? null;

            if ($userId === null || $userId === $actorUserId) {
                continue;
            }

            if (!$this->authorization->isGranted(
                AuthorizationSubject::authenticated($userId, false),
                Permission::IssueView,
                $scope,
            )) {
                continue;
            }

            $recipients[] = new Recipient((string) $membershipId, $userId, $kind);
        }

        return $recipients;
    }
}
