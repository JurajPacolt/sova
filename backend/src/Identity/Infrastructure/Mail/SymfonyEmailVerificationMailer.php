<?php

declare(strict_types=1);

namespace Sova\Identity\Infrastructure\Mail;

use DateTimeImmutable;
use InvalidArgumentException;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\EmailVerification\EmailVerificationMailer;
use Sova\Identity\Domain\Token\IssuedOneTimeToken;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyEmailVerificationMailer implements EmailVerificationMailer
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
        UserCredentials $user,
        IssuedOneTimeToken $token,
        DateTimeImmutable $expiresAt,
    ): void {
        $url = sprintf(
            '%s/verify-email/%s',
            $this->publicUrl,
            rawurlencode($token->plainText()),
        );
        $safeDisplayName = htmlspecialchars(
            $user->displayName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $safeUrl = htmlspecialchars(
            $url,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $safeExpiry = htmlspecialchars(
            $expiresAt->format(DATE_ATOM),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $message = (new Email())
            ->from($this->from)
            ->to($user->email)
            ->subject('Verify your SOVA email')
            ->text(sprintf(
                "Hello %s,\n\nVerify your SOVA email with this link:\n%s\n\n"
                . "The link expires at %s. If you did not expect this, ignore this email.\n",
                $user->displayName,
                $url,
                $expiresAt->format(DATE_ATOM),
            ))
            ->html(sprintf(
                '<p>Hello %s,</p>'
                . '<p><a href="%s">Verify your SOVA email</a></p>'
                . '<p>The link expires at %s. '
                . 'If you did not expect this, ignore this email.</p>',
                $safeDisplayName,
                $safeUrl,
                $safeExpiry,
            ));
        $message->getHeaders()->addTextHeader(
            'X-SOVA-Message-Type',
            'email-verification',
        );

        $this->mailer->send($message);
    }
}
