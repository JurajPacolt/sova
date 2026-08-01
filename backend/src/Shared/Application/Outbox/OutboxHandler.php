<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Outbox;

/**
 * Consumes outbox events of the names it declares.
 *
 * A handler runs inside the dispatcher's transaction, together with the write
 * that marks the event processed, so its effect and the acknowledgement commit
 * or roll back as one. Delivery is at-least-once, which makes idempotency the
 * handler's own responsibility: it must be safe to run twice for the same
 * event, usually by keying its writes on {@see OutboxEvent::$id}.
 *
 * Throwing asks for a retry with backoff. Handlers must not swallow a genuine
 * failure just to make the event go away.
 */
interface OutboxHandler
{
    /**
     * @return list<string> the event names this handler subscribes to
     */
    public function subscribedEvents(): array;

    public function handle(OutboxEvent $event): void;
}
