<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Mail;

use InvalidArgumentException;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Sova\Tenancy\Application\Invitation\InvitationMailer;
use Sova\Tenancy\Application\Invitation\TenantInvitation;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyInvitationMailer implements InvitationMailer
{
    private string $publicUrl;
    private string $from;

    public function __construct(
        private MailerInterface $mailer,
        Settings $settings,
    ) {
        $this->publicUrl = rtrim(
            $settings->string('app.public_url', ''),
            '/',
        );
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
        TenantInvitation $invitation,
        string $plainTextToken,
    ): void {
        $url = sprintf(
            '%s/accept-invitation/%s',
            $this->publicUrl,
            rawurlencode($plainTextToken),
        );
        $safeInviter = htmlspecialchars(
            $invitation->invitedByDisplayName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $safeTenant = htmlspecialchars(
            $invitation->tenantName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $safeUrl = htmlspecialchars(
            $url,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $safeExpiry = htmlspecialchars(
            $invitation->expiresAt->format(DATE_ATOM),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $message = (new Email())
            ->from($this->from)
            ->to($invitation->email)
            ->subject('You are invited to SOVA')
            ->text(sprintf(
                "%s invited you to %s in SOVA.\n\nAccept the invitation:\n%s\n\n"
                . "The invitation expires at %s. If you did not expect it, ignore this email.\n",
                $invitation->invitedByDisplayName,
                $invitation->tenantName,
                $url,
                $invitation->expiresAt->format(DATE_ATOM),
            ))
            ->html(sprintf(
                '<p>%s invited you to <strong>%s</strong> in SOVA.</p>'
                . '<p><a href="%s">Accept invitation</a></p>'
                . '<p>The invitation expires at %s. '
                . 'If you did not expect it, ignore this email.</p>',
                $safeInviter,
                $safeTenant,
                $safeUrl,
                $safeExpiry,
            ));
        $message->getHeaders()->addTextHeader(
            'X-SOVA-Message-Type',
            'tenant-invitation',
        );

        $this->mailer->send($message);
    }
}
