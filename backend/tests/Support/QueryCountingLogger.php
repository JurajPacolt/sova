<?php

declare(strict_types=1);

namespace Sova\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures only SQL executions emitted by Doctrine's logging middleware.
 *
 * Transaction lifecycle and connect/disconnect messages are deliberately not
 * counted: an N+1 budget describes database statements caused by a read, not
 * how the test harness owns its transaction.
 */
final class QueryCountingLogger extends AbstractLogger
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log(
        mixed $level,
        string|Stringable $message,
        array $context = [],
    ): void {
        $sql = $context['sql'] ?? null;

        if (is_string($sql) && str_starts_with((string) $message, 'Executing')) {
            $this->statements[] = $sql;
        }
    }

    public function reset(): void
    {
        $this->statements = [];
    }

    public function count(): int
    {
        return count($this->statements);
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }
}
