<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Sova\Issues\Application\Search\ReferenceRequest;
use Sova\Issues\Application\Search\ReferenceResolver;
use Sova\Issues\Application\Search\ResolvedReferences;
use Sova\Issues\Application\Search\SearchScope;

/**
 * Resolves the names a query mentions into identifiers, always constrained to
 * the scope's project list and tenant.
 *
 * Nothing here reports "forbidden": a project the caller cannot search simply
 * does not come back, and the compiler turns that into the same
 * `QUERY_VALUE_NOT_AVAILABLE` it uses for a code that never existed. That is
 * what stops a query from being used to enumerate another tenant's projects,
 * statuses, members or groups.
 */
final readonly class DoctrineReferenceResolver implements ReferenceResolver
{
    /**
     * The only two tables {@see self::configurationCodes()} may read. They are
     * class constants precisely so no caller can pass a table name through.
     */
    private const string ISSUE_TYPES = 'project_issue_types';

    private const string STATUSES = 'project_statuses';

    public function __construct(private Connection $connection) {}

    public function resolve(SearchScope $scope, ReferenceRequest $request): ResolvedReferences
    {
        if ($scope->isEmpty()) {
            return new ResolvedReferences([], [], [], [], [], [], [], [], null);
        }

        $workgroups = $this->workgroups(
            $scope,
            array_values(array_unique(array_merge(
                $request->workgroupReferences,
                $request->memberSetReferences,
            ))),
        );

        return new ResolvedReferences(
            $this->projects($scope, $request->projectCodes),
            $this->configurationCodes($scope, $request->issueTypeCodes, self::ISSUE_TYPES),
            $this->configurationCodes($scope, $request->statusCodes, self::STATUSES),
            $this->issueKeys($scope, $request->issueKeys),
            $this->memberships($scope, $request->memberReferences),
            $this->filterKeys($workgroups['ids'], $request->workgroupReferences),
            $this->groupMembers(
                $scope,
                $this->filterKeys($workgroups['ids'], $request->memberSetReferences),
            ),
            $workgroups['ambiguous'],
            $request->needsCurrentMember ? $this->currentMembership($scope) : null,
        );
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, string>
     */
    private function projects(SearchScope $scope, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->pairs(
            <<<'SQL'
                SELECT UPPER(project.code) AS reference, project.id AS identifier
                FROM projects project
                WHERE project.tenant_id = :tenant_id
                    AND project.id IN (:project_ids)
                    AND UPPER(project.code) IN (:codes)
                SQL,
            [
                'tenant_id' => $scope->tenantId,
                'project_ids' => $scope->projectIds,
                'codes' => $codes,
            ],
        );
    }

    /**
     * One code legitimately names an entity in several projects, so this returns
     * every identifier the caller may reach under that code.
     *
     * @param list<string>                             $codes
     * @param self::ISSUE_TYPES|self::STATUSES         $table
     *
     * @return array<string, list<string>>
     */
    private function configurationCodes(SearchScope $scope, array $codes, string $table): array
    {
        if ($codes === []) {
            return [];
        }

        $sql = sprintf(
            <<<'SQL'
                SELECT UPPER(entity.code) AS reference, entity.id AS identifier
                FROM %s entity
                WHERE entity.tenant_id = :tenant_id
                    AND entity.project_id IN (:project_ids)
                    AND UPPER(entity.code) IN (:codes)
                SQL,
            $table,
        );

        $grouped = [];

        foreach ($this->rows($sql, [
            'tenant_id' => $scope->tenantId,
            'project_ids' => $scope->projectIds,
            'codes' => $codes,
        ]) as $row) {
            $grouped[$row['reference']][] = $row['identifier'];
        }

        return $grouped;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private function issueKeys(SearchScope $scope, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->pairs(
            <<<'SQL'
                SELECT UPPER(issue.issue_key) AS reference, issue.id AS identifier
                FROM issues issue
                WHERE issue.tenant_id = :tenant_id
                    AND issue.project_id IN (:project_ids)
                    AND UPPER(issue.issue_key) IN (:keys)
                SQL,
            [
                'tenant_id' => $scope->tenantId,
                'project_ids' => $scope->projectIds,
                'keys' => $keys,
            ],
        );
    }

    /**
     * `user("…")` names a member by their tenant membership identifier — the same
     * stable public identity the issue API already exposes as `membership_id`.
     *
     * @param list<string> $references
     *
     * @return array<string, string>
     */
    private function memberships(SearchScope $scope, array $references): array
    {
        $candidates = array_values(array_filter($references, $this->looksLikeUuid(...)));

        if ($candidates === []) {
            return [];
        }

        return $this->pairs(
            <<<'SQL'
                SELECT membership.id::text AS reference, membership.id AS identifier
                FROM tenant_memberships membership
                WHERE membership.tenant_id = :tenant_id
                    AND membership.id IN (:membership_ids)
                    AND membership.status = 'ACTIVE'
                SQL,
            [
                'tenant_id' => $scope->tenantId,
                'membership_ids' => $candidates,
            ],
        );
    }

    /**
     * A group may be named by identifier or by its tenant-unique name. A name
     * matching more than one group is reported as ambiguous instead of silently
     * picking one.
     *
     * @param list<string> $references
     *
     * @return array{ids: array<string, string>, ambiguous: list<string>}
     */
    private function workgroups(SearchScope $scope, array $references): array
    {
        if ($references === []) {
            return ['ids' => [], 'ambiguous' => []];
        }

        $rows = $this->rows(
            <<<'SQL'
                SELECT workgroup.id AS identifier,
                       workgroup.id::text AS by_id,
                       LOWER(workgroup.name) AS by_name
                FROM workgroups workgroup
                WHERE workgroup.tenant_id = :tenant_id
                    AND workgroup.status = 'ACTIVE'
                    AND (
                        workgroup.id::text IN (:references)
                        OR LOWER(workgroup.name) IN (:lowered)
                    )
                SQL,
            [
                'tenant_id' => $scope->tenantId,
                'references' => $references,
                'lowered' => array_map(strtolower(...), $references),
            ],
        );

        $matches = [];

        foreach ($references as $reference) {
            $lowered = strtolower($reference);

            foreach ($rows as $row) {
                if ($row['by_id'] === $reference || $row['by_name'] === $lowered) {
                    $matches[$reference][$row['identifier']] = true;
                }
            }
        }

        $ids = [];
        $ambiguous = [];

        foreach ($matches as $reference => $identifiers) {
            $found = array_keys($identifiers);

            if (count($found) > 1) {
                $ambiguous[] = (string) $reference;

                continue;
            }

            $ids[(string) $reference] = (string) $found[0];
        }

        return ['ids' => $ids, 'ambiguous' => $ambiguous];
    }

    /**
     * @param array<string, string> $workgroupIds
     *
     * @return array<string, list<string>>
     */
    private function groupMembers(SearchScope $scope, array $workgroupIds): array
    {
        if ($workgroupIds === []) {
            return [];
        }

        $rows = $this->rows(
            <<<'SQL'
                SELECT member.workgroup_id AS reference,
                       member.membership_id AS identifier
                FROM workgroup_members member
                INNER JOIN tenant_memberships membership
                    ON membership.tenant_id = member.tenant_id
                    AND membership.id = member.membership_id
                WHERE member.tenant_id = :tenant_id
                    AND member.workgroup_id IN (:workgroup_ids)
                    AND membership.status = 'ACTIVE'
                SQL,
            [
                'tenant_id' => $scope->tenantId,
                'workgroup_ids' => array_values($workgroupIds),
            ],
        );

        $byWorkgroup = [];

        foreach ($rows as $row) {
            $byWorkgroup[$row['reference']][] = $row['identifier'];
        }

        $byReference = [];

        foreach ($workgroupIds as $reference => $workgroupId) {
            $byReference[$reference] = $byWorkgroup[$workgroupId] ?? [];
        }

        return $byReference;
    }

    private function currentMembership(SearchScope $scope): ?string
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT membership.id
                FROM tenant_memberships membership
                WHERE membership.tenant_id = :tenant_id
                    AND membership.user_id = :user_id
                    AND membership.status = 'ACTIVE'
                SQL,
            [
                'tenant_id' => $scope->tenantId,
                'user_id' => $scope->effectiveUserId,
            ],
        );

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, string> $identifiers
     * @param list<string>          $wanted
     *
     * @return array<string, string>
     */
    private function filterKeys(array $identifiers, array $wanted): array
    {
        return array_intersect_key($identifiers, array_flip($wanted));
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function pairs(string $sql, array $parameters): array
    {
        $pairs = [];

        foreach ($this->rows($sql, $parameters) as $row) {
            $pairs[$row['reference']] = $row['identifier'];
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, string>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $types = [];

        foreach ($parameters as $name => $value) {
            if (is_array($value)) {
                $types[$name] = ArrayParameterType::STRING;
            }
        }

        $rows = [];

        foreach ($this->connection->fetchAllAssociative($sql, $parameters, $types) as $row) {
            $normalized = [];

            foreach ($row as $column => $value) {
                if (!is_string($value)) {
                    // Every projection here is a uuid or text column; anything
                    // else means the query changed and must not be guessed at.
                    continue;
                }

                $normalized[(string) $column] = $value;
            }

            $rows[] = $normalized;
        }

        return $rows;
    }
}
