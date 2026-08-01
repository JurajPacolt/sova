<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Link;

use Sova\Issues\Domain\Link\IssueLinkType;

/**
 * The stored shape of a link, used to authorise removal. Both endpoints are
 * carried so the caller can check that the link really belongs to the issue of
 * the route.
 */
final readonly class IssueLinkRecord
{
    public function __construct(
        public string $id,
        public string $sourceIssueId,
        public string $sourceProjectId,
        public string $targetIssueId,
        public string $targetProjectId,
        public IssueLinkType $type,
    ) {}
}
