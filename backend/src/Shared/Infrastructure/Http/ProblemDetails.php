<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Http;

final readonly class ProblemDetails
{
    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public string $instance,
        public string $requestId,
        public string $code,
        public array $fieldErrors = [],
    ) {}

    /**
     * @return array<string, int|string|array<string, list<string>>>
     */
    public function toArray(): array
    {
        $payload = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            'request_id' => $this->requestId,
            'code' => $this->code,
        ];

        if ($this->fieldErrors !== []) {
            $payload['errors'] = $this->fieldErrors;
        }

        return $payload;
    }
}
