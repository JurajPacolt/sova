<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use Sova\Issues\Application\IssueRepository;
use Sova\Shared\Application\Outbox\OutboxEvent;
use Sova\Shared\Application\Outbox\OutboxHandler;

/**
 * Writes the in-app half of a notification.
 *
 * Idempotency is delegated to storage: every delivery is keyed on the outbox
 * event, so a replay writes nothing new. That is what lets the dispatcher
 * guarantee only at-least-once and still leave a clean inbox.
 *
 * The audience comes from {@see NotificationAudience}, shared with the e-mail
 * handler so the two channels can never disagree about who may be told what.
 */
final readonly class IssueEventNotifier implements OutboxHandler
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationAudience $audience,
        private PreferenceRepository $preferences,
        private IssueRepository $issues,
    ) {}

    public function subscribedEvents(): array
    {
        return ['ISSUE_CREATED', 'ISSUE_TRANSITIONED', 'COMMENT_ADDED'];
    }

    public function handle(OutboxEvent $event): void
    {
        $tenantId = $event->tenantId;

        if ($tenantId === null) {
            return;
        }

        $issueId = $event->eventName === 'COMMENT_ADDED'
            ? $event->string('issue_id')
            : $event->aggregateId;

        if ($issueId === null) {
            return;
        }

        $issue = $this->issues->find($tenantId, $issueId);

        if ($issue === null) {
            // The issue is gone. Delivering against a dangling reference would
            // be worse than dropping the event, and the row is acknowledged, so
            // this does not retry forever.
            return;
        }

        $recipients = $this->audience->resolve($event, $issue);

        if ($recipients === []) {
            return;
        }

        $preferences = $this->preferences->forMembers(
            $tenantId,
            array_map(
                static fn(Recipient $recipient): string => $recipient->membershipId,
                $recipients,
            ),
        );

        $payload = [
            'issue_key' => $issue->key,
            'issue_title' => $issue->title,
            'project_id' => $issue->projectId,
        ];
        $commentId = $event->string('comment_id');

        if ($commentId !== null) {
            $payload['comment_id'] = $commentId;
        }

        foreach ($recipients as $recipient) {
            $choice = $preferences[$recipient->membershipId][$recipient->kind->value]
                ?? ChannelPreference::default($recipient->kind);

            if (!$choice->inApp) {
                continue;
            }

            $this->notifications->deliver(
                $tenantId,
                $event->id,
                $recipient->membershipId,
                $recipient->kind->value,
                $issue->projectId,
                $issue->id,
                $event->string('actor_user_id'),
                $payload,
            );
        }
    }
}
