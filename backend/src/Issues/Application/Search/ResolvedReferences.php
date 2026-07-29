<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

/**
 * The outcome of resolving a {@see ReferenceRequest} inside a {@see SearchScope}.
 *
 * A reference that exists but lies outside the caller's scope is simply absent
 * here, exactly like one that never existed — the compiler turns both into the
 * same `QUERY_VALUE_NOT_AVAILABLE`, so a query can never be used to probe another
 * tenant's or project's configuration.
 *
 * Codes are not unique across projects: `type = BUG` legitimately means "the BUG
 * type of every project I may search", which is why the type and status maps
 * hold a list of identifiers rather than one.
 */
final readonly class ResolvedReferences
{
    /**
     * @param array<string, string>       $projectIdByCode
     * @param array<string, list<string>> $issueTypeIdsByCode
     * @param array<string, list<string>> $statusIdsByCode
     * @param array<string, string>       $issueIdByKey
     * @param array<string, string>       $membershipIdByReference
     * @param array<string, string>       $workgroupIdByReference
     * @param array<string, list<string>> $membershipIdsByGroupReference
     * @param list<string>                $ambiguousReferences references whose
     *                                                        name matches more
     *                                                        than one workgroup
     */
    public function __construct(
        public array $projectIdByCode,
        public array $issueTypeIdsByCode,
        public array $statusIdsByCode,
        public array $issueIdByKey,
        public array $membershipIdByReference,
        public array $workgroupIdByReference,
        public array $membershipIdsByGroupReference,
        public array $ambiguousReferences,
        public ?string $currentMembershipId,
    ) {}

    public function isAmbiguous(string $reference): bool
    {
        return in_array($reference, $this->ambiguousReferences, true);
    }
}
