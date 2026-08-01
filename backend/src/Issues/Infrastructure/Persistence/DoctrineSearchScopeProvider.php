<?php

declare(strict_types=1);

namespace Sova\Issues\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Issues\Application\Search\SearchScope;
use Sova\Issues\Application\Search\SearchScopeProvider;

/**
 * Builds the search boundary from the database.
 *
 * The project list mirrors `DoctrineEffectivePermissionProvider::loadProjectDecision`
 * exactly — a direct project role assignment or a linked workgroup — but asks it
 * once for every project instead of once per project, so a search never degrades
 * into N authorization round trips. Any change to project authorization has to
 * be made in both places or the two will disagree.
 *
 * A superadmin acting in their own context keeps the documented full bypass; the
 * effective user during impersonation does not, which is why the subject decides
 * rather than the session.
 */
final readonly class DoctrineSearchScopeProvider implements SearchScopeProvider
{
    public function __construct(private Connection $connection) {}

    public function scopeFor(AuthorizationSubject $subject, string $tenantId): SearchScope
    {
        $projectIds = $subject->hasSuperadminBypass()
            ? $this->allActiveProjects($tenantId)
            : $this->viewableProjects($tenantId, $subject->effectiveUserId);

        return new SearchScope(
            $tenantId,
            $subject->effectiveUserId,
            $projectIds,
            $this->authorizationRevision($tenantId),
        );
    }

    /**
     * @return list<string>
     */
    private function allActiveProjects(string $tenantId): array
    {
        return $this->identifiers(
            <<<'SQL'
                SELECT project.id
                FROM projects project
                INNER JOIN tenants tenant
                    ON tenant.id = project.tenant_id
                WHERE project.tenant_id = :tenant_id
                    AND project.status = 'ACTIVE'
                    AND tenant.status = 'ACTIVE'
                SQL,
            ['tenant_id' => $tenantId],
        );
    }

    /**
     * @return list<string>
     */
    private function viewableProjects(string $tenantId, string $userId): array
    {
        return $this->identifiers(
            <<<'SQL'
                SELECT DISTINCT project.id
                FROM projects project
                INNER JOIN tenants tenant
                    ON tenant.id = project.tenant_id
                INNER JOIN project_roles role
                    ON role.tenant_id = project.tenant_id
                    AND role.project_id = project.id
                INNER JOIN project_role_permissions role_permission
                    ON role_permission.tenant_id = role.tenant_id
                    AND role_permission.project_id = role.project_id
                    AND role_permission.role_id = role.id
                WHERE project.tenant_id = :tenant_id
                    AND project.status = 'ACTIVE'
                    AND tenant.status = 'ACTIVE'
                    AND role.status = 'ACTIVE'
                    AND role_permission.permission_code = :permission_code
                    AND (
                        EXISTS (
                            SELECT 1
                            FROM project_membership_role_assignments assignment
                            INNER JOIN tenant_memberships membership
                                ON membership.tenant_id = assignment.tenant_id
                                AND membership.id = assignment.membership_id
                            INNER JOIN users user_account
                                ON user_account.id = membership.user_id
                            WHERE assignment.tenant_id = role.tenant_id
                                AND assignment.project_id = role.project_id
                                AND assignment.role_id = role.id
                                AND membership.user_id = :user_id
                                AND membership.status = 'ACTIVE'
                                AND user_account.status = 'ACTIVE'
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM project_workgroups linked
                            INNER JOIN workgroups workgroup
                                ON workgroup.tenant_id = linked.tenant_id
                                AND workgroup.id = linked.workgroup_id
                            INNER JOIN workgroup_members workgroup_member
                                ON workgroup_member.tenant_id = linked.tenant_id
                                AND workgroup_member.workgroup_id = linked.workgroup_id
                            INNER JOIN tenant_memberships membership
                                ON membership.tenant_id = workgroup_member.tenant_id
                                AND membership.id = workgroup_member.membership_id
                            INNER JOIN users user_account
                                ON user_account.id = membership.user_id
                            WHERE linked.tenant_id = role.tenant_id
                                AND linked.project_id = role.project_id
                                AND linked.role_id = role.id
                                AND membership.user_id = :user_id
                                AND membership.status = 'ACTIVE'
                                AND user_account.status = 'ACTIVE'
                                AND workgroup.status = 'ACTIVE'
                        )
                    )
                SQL,
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'permission_code' => Permission::IssueView->value,
            ],
        );
    }

    /**
     * The cursor is bound to this number, so a permission change invalidates
     * every page token the caller is holding.
     */
    private function authorizationRevision(string $tenantId): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT revision
                FROM tenant_authorization_revisions
                WHERE tenant_id = :tenant_id
                SQL,
            ['tenant_id' => $tenantId],
        );

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<string>
     */
    private function identifiers(string $sql, array $parameters): array
    {
        $identifiers = [];

        foreach ($this->connection->fetchFirstColumn($sql, $parameters) as $value) {
            if (is_string($value)) {
                $identifiers[] = $value;
            }
        }

        return $identifiers;
    }
}
