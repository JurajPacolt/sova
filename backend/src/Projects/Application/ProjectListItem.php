<?php

declare(strict_types=1);

namespace Sova\Projects\Application;

final readonly class ProjectListItem
{
    /**
     * @param list<string> $viewerRoleCodes active project role codes the
     *                                      requesting user holds, either through a direct
     *                                      assignment or through a linked workgroup
     */
    public function __construct(
        public ProjectDetails $project,
        public array $viewerRoleCodes,
    ) {}
}
