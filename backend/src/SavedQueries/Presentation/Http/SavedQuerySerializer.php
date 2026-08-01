<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Presentation\Http;

use Sova\SavedQueries\Application\SavedQuery;
use Sova\SavedQueries\Application\SavedQueryGrant;

final readonly class SavedQuerySerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(SavedQuery $query): array
    {
        return [
            'id' => $query->id,
            'name' => $query->name,
            'description' => $query->description,
            'raw_query' => $query->rawQuery,
            // Produced by the server; a client that sent one would be ignored.
            'canonical_query' => $query->canonicalQuery,
            'language_version' => $query->languageVersion,
            'default_columns' => $query->defaultColumns,
            'visibility' => $query->visibility->value,
            'version' => $query->version,
            'archived' => $query->archived,
            'owner' => [
                'membership_id' => $query->ownerMembershipId,
                'display_name' => $query->ownerDisplayName,
            ],
            // What *this* caller may do. The same row answers differently for
            // its owner, a grant holder and an administrator.
            'viewer_access' => $query->viewerAccess->value,
            'viewer_is_owner' => $query->viewerIsOwner,
            'favourite' => $query->favourite,
            'created_at' => $query->createdAt->format(DATE_ATOM),
            'updated_at' => $query->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeGrant(SavedQueryGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'membership_id' => $grant->membershipId,
            'workgroup_id' => $grant->workgroupId,
            'display_name' => $grant->displayName,
            'access' => $grant->access->value,
        ];
    }
}
