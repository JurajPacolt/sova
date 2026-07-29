<?php

declare(strict_types=1);

namespace Sova\Notifications\Domain;

/**
 * The event kinds a member can be told about, with the channel policy of
 * webflow §12.
 *
 * Being assigned work and being addressed by name are not things a member may
 * silently miss, so their in-app channel is mandatory. Everything else is
 * theirs to switch off. E-mail is always optional here; the mandatory security
 * mail of the same table is account-level and does not travel this path at all.
 */
enum NotificationKind: string
{
    case Assigned = 'ISSUE_ASSIGNED';
    case Mentioned = 'ISSUE_MENTIONED';
    case Commented = 'ISSUE_COMMENTED';
    case Transitioned = 'ISSUE_TRANSITIONED';

    public function inAppIsMandatory(): bool
    {
        return $this === self::Assigned || $this === self::Mentioned;
    }

    public function defaultInApp(): bool
    {
        return true;
    }

    /**
     * E-mail is off by default. Turning it on is an explicit choice, so a
     * busy project cannot start mailing everyone because nobody looked at the
     * settings.
     */
    public function defaultEmail(): bool
    {
        return false;
    }
}
