<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

use Sova\Notifications\Domain\NotificationKind;

/**
 * What a member wants for one kind of event. A mandatory in-app channel is
 * forced on here rather than trusted to the caller, so no storage or API path
 * can turn it off by mistake.
 */
final readonly class ChannelPreference
{
    public bool $inApp;

    public function __construct(
        public NotificationKind $kind,
        bool $inApp,
        public bool $email,
    ) {
        $this->inApp = $kind->inAppIsMandatory() ? true : $inApp;
    }

    public static function default(NotificationKind $kind): self
    {
        return new self($kind, $kind->defaultInApp(), $kind->defaultEmail());
    }
}
