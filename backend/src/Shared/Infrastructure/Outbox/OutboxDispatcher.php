<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Outbox;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use JsonException;
use Sova\Shared\Application\Outbox\OutboxEvent;
use Sova\Shared\Application\Outbox\OutboxHandler;
use Sova\Shared\Infrastructure\Configuration\Settings;
use Throwable;

/**
 * The generic transactional-outbox worker.
 *
 * It claims one event at a time with `FOR UPDATE … SKIP LOCKED`, so several
 * worker processes can run side by side without ever handing the same event to
 * two of them. The handlers and the row that marks the event processed commit
 * together: either the effect and the acknowledgement both land, or neither
 * does and the event is retried. That is what makes delivery at-least-once
 * rather than at-most-once, and why handlers have to be idempotent.
 *
 * Only events with a registered handler are claimed. The email workers own
 * their own names and their encrypted payloads, so this dispatcher must not
 * take those rows from under them.
 *
 * A failing event backs off exponentially and is abandoned after the configured
 * number of attempts, with the reason recorded — a poison message must not stall
 * the queue behind it forever.
 */
final readonly class OutboxDispatcher
{
    private int $maxAttempts;

    /** @var array<string, list<OutboxHandler>> */
    private array $handlers;

    /**
     * @param iterable<OutboxHandler> $handlers
     */
    public function __construct(
        private Connection $connection,
        iterable $handlers,
        Settings $settings,
    ) {
        $this->maxAttempts = max(1, $settings->int('outbox.max_attempts', 5));

        $registry = [];

        foreach ($handlers as $handler) {
            foreach ($handler->subscribedEvents() as $eventName) {
                $registry[$eventName][] = $handler;
            }
        }

        $this->handlers = $registry;
    }

    /**
     * @return int events attempted, so the caller can idle when there is
     *             nothing to do instead of spinning
     */
    public function runBatch(int $limit = 20): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException(
                'The outbox batch limit must be between 1 and 100.',
            );
        }

        if ($this->handlers === []) {
            return 0;
        }

        $attempted = 0;

        while ($attempted < $limit && $this->processNext()) {
            ++$attempted;
        }

        return $attempted;
    }

    private function processNext(): bool
    {
        $eventId = null;

        try {
            return $this->connection->transactional(function () use (&$eventId): bool {
                $row = $this->connection->fetchAssociative(
                    <<<'SQL'
                        SELECT event.id,
                               event.tenant_id,
                               event.aggregate_type,
                               event.aggregate_id,
                               event.event_name,
                               event.sequence_number,
                               event.payload
                        FROM outbox_events event
                        WHERE event.event_name IN (:event_names)
                            AND event.processed_at IS NULL
                            AND event.failed_at IS NULL
                            AND event.available_at <= CURRENT_TIMESTAMP
                        ORDER BY event.created_at, event.id
                        FOR UPDATE SKIP LOCKED
                        LIMIT 1
                        SQL,
                    ['event_names' => array_keys($this->handlers)],
                    ['event_names' => ArrayParameterType::STRING],
                );

                if ($row === false) {
                    return false;
                }

                $event = $this->hydrate($row);
                $eventId = $event->id;

                foreach ($this->handlers[$event->eventName] ?? [] as $handler) {
                    $handler->handle($event);
                }

                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE outbox_events
                        SET processed_at = CURRENT_TIMESTAMP,
                            last_error = NULL
                        WHERE id = :event_id
                        SQL,
                    ['event_id' => $eventId],
                );

                return true;
            });
        } catch (Throwable $exception) {
            if ($eventId === null) {
                // The failure happened before any event was identified, so
                // there is nothing to back off; let the caller see it.
                throw $exception;
            }

            $this->recordFailure($eventId, $exception);

            return true;
        }
    }

    private function recordFailure(string $eventId, Throwable $exception): void
    {
        $attemptCount = $this->connection->fetchOne(
            <<<'SQL'
                SELECT attempt_count
                FROM outbox_events
                WHERE id = :event_id
                    AND processed_at IS NULL
                    AND failed_at IS NULL
                SQL,
            ['event_id' => $eventId],
        );

        if (!is_int($attemptCount) && !is_string($attemptCount)) {
            return;
        }

        $nextAttempt = (int) $attemptCount + 1;
        // The class name is safe to store; the message could carry payload
        // detail, so it stays out of the column.
        $reason = substr($exception::class, 0, 512);

        if ($nextAttempt >= $this->maxAttempts) {
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE outbox_events
                    SET attempt_count = :attempt_count,
                        failed_at = CURRENT_TIMESTAMP,
                        last_error = :reason
                    WHERE id = :event_id
                        AND processed_at IS NULL
                        AND failed_at IS NULL
                    SQL,
                [
                    'attempt_count' => $nextAttempt,
                    'reason' => $reason,
                    'event_id' => $eventId,
                ],
            );

            return;
        }

        $backoffSeconds = min(3_600, 30 * (2 ** min($nextAttempt - 1, 6)));

        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    UPDATE outbox_events
                    SET attempt_count = :attempt_count,
                        available_at = CURRENT_TIMESTAMP + INTERVAL '%d seconds',
                        last_error = :reason
                    WHERE id = :event_id
                        AND processed_at IS NULL
                        AND failed_at IS NULL
                    SQL,
                $backoffSeconds,
            ),
            [
                'attempt_count' => $nextAttempt,
                'reason' => $reason,
                'event_id' => $eventId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxEvent
    {
        $payload = [];
        $raw = $row['payload'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        $payload[(string) $key] = $value;
                    }
                }
            } catch (JsonException) {
                $payload = [];
            }
        }

        return new OutboxEvent(
            $this->string($row, 'id'),
            is_string($row['tenant_id'] ?? null) ? $row['tenant_id'] : null,
            $this->string($row, 'aggregate_type'),
            $this->string($row, 'aggregate_id'),
            $this->string($row, 'event_name'),
            (int) $this->string($row, 'sequence_number'),
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
