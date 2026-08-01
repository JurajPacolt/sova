<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

interface InvitationMailer
{
    public function send(TenantInvitation $invitation, string $plainTextToken): void;
}
