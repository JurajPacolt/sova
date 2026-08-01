<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\HierarchyLevel;

/**
 * Everything issue tracking needs to place a new issue: the active type, the
 * workflow version published for it and that version's initial status. Issue
 * tracking never derives these from the configuration tables itself.
 */
final readonly class IssueCreationTarget
{
    public function __construct(
        public string $issueTypeId,
        public string $issueTypeCode,
        public HierarchyLevel $hierarchyLevel,
        public string $workflowVersionId,
        public string $initialStatusId,
    ) {}
}
