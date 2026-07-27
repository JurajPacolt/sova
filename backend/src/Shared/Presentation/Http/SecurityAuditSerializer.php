<?php

declare(strict_types=1);

namespace Sova\Shared\Presentation\Http;

use Sova\Shared\Application\Audit\AuditActor;
use Sova\Shared\Application\Audit\AuditTenant;
use Sova\Shared\Application\Audit\SecurityAuditEventDetails;
use Sova\Shared\Application\Audit\SecurityAuditPage;

final class SecurityAuditSerializer
{
    /**
     * @return array{
     *     events: list<array<string, mixed>>,
     *     next_cursor: string|null
     * }
     */
    public function page(SecurityAuditPage $page): array
    {
        return [
            'events' => array_map($this->event(...), $page->events),
            'next_cursor' => $page->nextCursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(SecurityAuditEventDetails $event): array
    {
        return [
            'id' => $event->id,
            'actor' => $this->actor($event->actor),
            'effective_user' => $event->effectiveUser === null
                ? null
                : $this->actor($event->effectiveUser),
            'tenant' => $event->tenant === null
                ? null
                : $this->tenant($event->tenant),
            'event_type' => $event->eventType,
            'outcome' => $event->outcome,
            'reason_code' => $event->reasonCode,
            'request_id' => $event->requestId,
            'ip_address' => $event->ipAddress,
            'metadata' => $event->metadata,
            'occurred_at' => $event->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array{id: string, email: string, display_name: string}
     */
    private function actor(AuditActor $actor): array
    {
        return [
            'id' => $actor->id,
            'email' => $actor->email,
            'display_name' => $actor->displayName,
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string}
     */
    private function tenant(AuditTenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ];
    }
}
