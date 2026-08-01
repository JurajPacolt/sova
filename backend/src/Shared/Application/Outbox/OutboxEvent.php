<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Outbox;

/**
 * One claimed outbox event handed to its handlers.
 *
 * Delivery is at-least-once, so {@see $id} is the natural idempotency key: a
 * handler that records it alongside its effect can recognise a replay instead
 * of duplicating the work.
 */
final readonly class OutboxEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $aggregateType,
        public string $aggregateId,
        public string $eventName,
        public int $sequenceNumber,
        public array $payload,
    ) {}

    public function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->payload[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
