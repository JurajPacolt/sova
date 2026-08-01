<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Application;

use Sova\SavedQueries\Domain\SavedQueryAccess;

/** One explicit grant: a member or a workgroup, never both. */
final readonly class SavedQueryGrant
{
    public function __construct(
        public string $id,
        public ?string $membershipId,
        public ?string $workgroupId,
        public ?string $displayName,
        public SavedQueryAccess $access,
    ) {}
}
