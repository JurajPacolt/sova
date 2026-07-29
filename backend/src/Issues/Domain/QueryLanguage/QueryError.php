<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use JsonSerializable;

/**
 * A single validation error carrying a stable code and the offending range in
 * the original query text (UTF-8 codepoint offsets, {@see $start} inclusive and
 * {@see $end} exclusive), plus optional structured arguments for the message.
 */
final readonly class QueryError implements JsonSerializable
{
    /**
     * @param array<string, string|int> $arguments
     */
    public function __construct(
        public QueryErrorCode $code,
        public int $start,
        public int $end,
        public array $arguments = [],
    ) {}

    /**
     * @return array{
     *     code: string,
     *     message_key: string,
     *     start: int,
     *     end: int,
     *     arguments: array<string, string|int>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code->value,
            'message_key' => $this->code->messageKey(),
            'start' => $this->start,
            'end' => $this->end,
            'arguments' => $this->arguments,
        ];
    }
}
