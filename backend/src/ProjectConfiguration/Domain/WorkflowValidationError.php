<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

/**
 * A single reason a workflow draft cannot be published. The stable `code`
 * lets the frontend localize and group problems; `detail` is a safe English
 * fallback.
 */
final readonly class WorkflowValidationError
{
    public function __construct(
        public string $code,
        public string $detail,
    ) {}
}
