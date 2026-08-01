<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;

interface InvitationPublisher
{
    public function publish(
        string $invitationId,
        string $tenantId,
        string $plainTextToken,
        DateTimeImmutable $deliveryExpiresAt,
    ): void;
}
