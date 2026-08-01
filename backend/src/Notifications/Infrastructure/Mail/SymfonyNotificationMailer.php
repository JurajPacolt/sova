<?php

declare(strict_types=1);

namespace Sova\Notifications\Infrastructure\Mail;

use InvalidArgumentException;
use Sova\Notifications\Application\MemberContact;
use Sova\Notifications\Application\NotificationMailer;
use Sova\Notifications\Domain\NotificationKind;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends the notification e-mail.
 *
 * The body carries the issue key, its title and a link back into the
 * application — never the comment text or any other content. E-mail leaves the
 * system's control the moment it is handed to the transport, so it points at
 * the material instead of containing it, and opening the link goes through the
 * usual authorisation.
 *
 * Every interpolated value is HTML-escaped: an issue title is user input, and
 * a mail client is as good a place to land an injected tag as a browser.
 */
final readonly class SymfonyNotificationMailer implements NotificationMailer
{
    private string $publicUrl;
    private string $from;

    public function __construct(
        private MailerInterface $mailer,
        Settings $settings,
    ) {
        $this->publicUrl = rtrim($settings->string('app.public_url', ''), '/');
        $this->from = $settings->string('mailer.from', '');

        if (filter_var($this->publicUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(
                'APP_PUBLIC_URL must contain an absolute URL.',
            );
        }

        if (filter_var($this->from, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                'MAILER_FROM must contain a valid email address.',
            );
        }
    }

    public function send(
        MemberContact $contact,
        NotificationKind $kind,
        string $issueKey,
        string $issueTitle,
    ): void {
        $safeKey = $this->escape($issueKey);
        $safeTitle = $this->escape($issueTitle);
        $subject = sprintf('%s %s: %s', $this->summary($kind), $issueKey, $issueTitle);
        $url = sprintf('%s/issues/%s', $this->publicUrl, rawurlencode($issueKey));

        $this->mailer->send(
            (new Email())
                ->from($this->from)
                ->to($contact->email)
                ->subject(mb_substr($subject, 0, 180))
                ->text(sprintf(
                    "%s\n\n%s: %s\n\n%s\n",
                    $this->summary($kind),
                    $issueKey,
                    $issueTitle,
                    $url,
                ))
                ->html(sprintf(
                    '<p>%s</p><p><a href="%s">%s</a>: %s</p>',
                    $this->escape($this->summary($kind)),
                    $this->escape($url),
                    $safeKey,
                    $safeTitle,
                )),
        );
    }

    private function summary(NotificationKind $kind): string
    {
        return match ($kind) {
            NotificationKind::Assigned => 'An issue was assigned to you',
            NotificationKind::Mentioned => 'You were mentioned on an issue',
            NotificationKind::Commented => 'A new comment on an issue you watch',
            NotificationKind::Transitioned => 'An issue you watch changed status',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
