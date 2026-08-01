<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final readonly class ApplicationContextProcessor implements ProcessorInterface
{
    public function __construct(
        private string $service,
        private string $environment,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            'environment' => $this->environment,
            'service' => $this->service,
        ]);
    }
}
