<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;

/**
 * Every distinct external reference a validated query mentions, collected in one
 * AST walk so they can be resolved with a handful of bulk queries instead of one
 * per predicate. Positions are deliberately not kept here: the compiler already
 * holds the value nodes and reports the offending range itself.
 */
final readonly class ReferenceRequest
{
    /**
     * @param list<string> $projectCodes
     * @param list<string> $issueTypeCodes
     * @param list<string> $statusCodes
     * @param list<string> $issueKeys
     * @param list<string> $memberReferences  arguments of `user(...)`
     * @param list<string> $workgroupReferences arguments of `group(...)`
     * @param list<string> $memberSetReferences arguments of `membersOf(...)`
     */
    public function __construct(
        public array $projectCodes,
        public array $issueTypeCodes,
        public array $statusCodes,
        public array $issueKeys,
        public array $memberReferences,
        public array $workgroupReferences,
        public array $memberSetReferences,
        public bool $needsCurrentMember,
    ) {}

    public static function collect(Query $query, FieldCatalog $fields): self
    {
        $collector = new ReferenceCollector($fields);
        $collector->walk($query);

        return $collector->request();
    }
}
