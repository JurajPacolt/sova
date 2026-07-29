<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Link;

use DateTimeImmutable;
use Sova\Issues\Domain\Link\IssueLinkType;

/**
 * A link as seen from one issue. {@see $relation} is what the link means from
 * that side — the stored type when the issue is the source, the inverse label
 * when it is the target — so a caller never has to work out the direction.
 */
final readonly class IssueLink
{
    public function __construct(
        public string $id,
        public IssueLinkType $type,
        public string $relation,
        public bool $outward,
        public string $otherIssueId,
        public string $otherIssueKey,
        public string $otherIssueTitle,
        public string $otherProjectId,
        public string $otherStatusCode,
        public string $otherStatusCategory,
        public DateTimeImmutable $createdAt,
    ) {}
}
