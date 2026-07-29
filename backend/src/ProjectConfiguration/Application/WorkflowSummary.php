<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\ConfigurationStatus;

/**
 * A workflow identity together with its published and draft versions. Either
 * version may be absent: a freshly created workflow has no draft, a workflow
 * mid-authoring has both.
 */
final readonly class WorkflowSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ?string $activeVersionId,
        public ConfigurationStatus $status,
        public ?WorkflowVersionView $publishedVersion,
        public ?WorkflowVersionView $draftVersion,
    ) {}
}
