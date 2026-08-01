<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use Sova\Notifications\Domain\NotificationKind;

/**
 * Sends a notification e-mail.
 *
 * The port takes the issue key and title but never the comment body or any
 * other content: e-mail leaves the system's control once it is handed over, so
 * it carries a pointer back into the application rather than the material
 * itself.
 */
interface NotificationMailer
{
    public function send(
        MemberContact $contact,
        NotificationKind $kind,
        string $issueKey,
        string $issueTitle,
    ): void;
}
