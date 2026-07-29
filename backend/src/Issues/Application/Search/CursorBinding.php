<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * Everything a page token is tied to. The fingerprint goes into the signature,
 * so a token stops verifying the moment any of it changes — a different tenant
 * or user, a permission change (via the authorization revision), an edited
 * query, or a different sort order.
 */
final readonly class CursorBinding
{
    /**
     * @param list<CompiledSort> $sort
     */
    public function __construct(
        private string $tenantId,
        private string $effectiveUserId,
        private int $authorizationRevision,
        private string $canonicalQuery,
        private array $sort,
    ) {}

    public function fingerprint(): string
    {
        $sort = [];

        foreach ($this->sort as $item) {
            $sort[] = sprintf(
                '%s:%s:%s',
                $item->field,
                $item->direction->value,
                $item->nullsFirst ? 'NF' : 'NL',
            );
        }

        return hash('sha256', implode("\x1f", [
            $this->tenantId,
            $this->effectiveUserId,
            (string) $this->authorizationRevision,
            hash('sha256', $this->canonicalQuery),
            implode(',', $sort),
        ]));
    }
}
