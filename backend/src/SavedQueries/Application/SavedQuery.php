<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Application;

use DateTimeImmutable;
use Sova\SavedQueries\Domain\SavedQueryAccess;
use Sova\SavedQueries\Domain\SavedQueryVisibility;

/**
 * A saved query as the API returns it.
 *
 * {@see $viewerAccess} is what the caller may do with it, resolved per request:
 * the same stored row answers differently for its owner, for someone holding a
 * grant, and for a tenant administrator. It says nothing about the issues the
 * query would return — that intersection is computed again at execution time.
 */
final readonly class SavedQuery
{
    /**
     * @param list<string> $defaultColumns
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $ownerMembershipId,
        public ?string $ownerDisplayName,
        public string $name,
        public string $description,
        public string $rawQuery,
        public string $canonicalQuery,
        public int $languageVersion,
        public array $defaultColumns,
        public SavedQueryVisibility $visibility,
        public int $version,
        public bool $archived,
        public SavedQueryAccess $viewerAccess,
        public bool $viewerIsOwner,
        public bool $favourite,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
