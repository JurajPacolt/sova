<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use Sova\Issues\Application\IssueRepository;
use Sova\Shared\Application\Outbox\OutboxEvent;
use Sova\Shared\Application\Outbox\OutboxHandler;

/**
 * Sends the e-mail half of a notification, for members who asked for it.
 *
 * It shares {@see NotificationAudience} with the in-app handler, so a person
 * who may not see the issue cannot be reached through this channel either. The
 * message deliberately carries only the issue key, its title and a pointer —
 * never the comment body — because e-mail leaves the system's control the
 * moment it is handed over.
 *
 * Both handlers run inside one dispatcher transaction, so a message can be sent
 * and the transaction still roll back, which would send it again on the retry.
 * That is the accepted at-least-once trade-off of the existing e-mail workers;
 * a duplicate notification is a nuisance, a lost one is a defect.
 */
final readonly class NotificationEmailHandler implements OutboxHandler
{
    public function __construct(
        private NotificationAudience $audience,
        private PreferenceRepository $preferences,
        private MemberDirectory $members,
        private NotificationMailer $mailer,
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

        foreach ($recipients as $recipient) {
            $choice = $preferences[$recipient->membershipId][$recipient->kind->value]
                ?? ChannelPreference::default($recipient->kind);

            if (!$choice->email) {
                continue;
            }

            $contact = $this->members->contactFor($tenantId, $recipient->membershipId);

            if ($contact === null) {
                continue;
            }

            $this->mailer->send($contact, $recipient->kind, $issue->key, $issue->title);
        }
    }
}
