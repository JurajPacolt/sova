<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Row level security on every tenant-owned table.
 *
 * This is the last layer of the defence described in `ANALYZA_PROJEKTU.md` §7,
 * not the first one. The application still writes `tenant_id` into every
 * statement; the policy is what catches the day somebody forgets. That ordering
 * matters for how the policy is written: it must be impossible for RLS to break
 * a request that is already correct, or it would be removed the first time it
 * was inconvenient.
 *
 * The policy reads the tenant from `sova.tenant_id`, a session setting the HTTP
 * layer applies around a tenant-scoped request. **An unset setting means "no
 * tenant scope", not "no rows."** Login, tenant selection, system
 * administration, the outbox workers and the migrations themselves all run
 * without a tenant, and a policy that denied them would deny the very code that
 * has to see across tenants by design.
 *
 * `FORCE` is not optional here: the application connects as the owner of these
 * tables, and an owner is exempt from its own policies without it.
 *
 * Two tables allow a null `tenant_id`, because a security event or an outbox
 * message can belong to the system rather than to a tenant. They are treated
 * asymmetrically on purpose: a tenant-scoped **read** never returns a row that
 * belongs to no tenant, while a tenant-scoped **write** may still record one —
 * refusing that would fail a write for a reason that has nothing to do with the
 * tenant.
 */
final class Version20260729100000 extends AbstractMigration
{
    /** Tables whose `tenant_id` is `NOT NULL`, so the policy can be symmetric. */
    private const array TENANT_OWNED = [
        'dashboard_widgets',
        'dashboards',
        'impersonations',
        'issue_attachments',
        'issue_comment_mentions',
        'issue_comments',
        'issue_history',
        'issue_links',
        'issue_watchers',
        'issues',
        'membership_dashboard_preferences',
        'notification_preferences',
        'notifications',
        'project_configuration_history',
        'project_configuration_revisions',
        'project_issue_counters',
        'project_issue_type_workflows',
        'project_issue_types',
        'project_membership_role_assignments',
        'project_role_permissions',
        'project_roles',
        'project_statuses',
        'project_workflow_transitions',
        'project_workflow_versions',
        'project_workflows',
        'project_workgroups',
        'projects',
        'saved_queries',
        'saved_query_favourites',
        'saved_query_grants',
        'system_tenant_creation_requests',
        'tenant_authorization_revisions',
        'tenant_invitations',
        'tenant_membership_role_assignments',
        'tenant_memberships',
        'tenant_role_permissions',
        'tenant_roles',
        'workflow_transition_rules',
        'workflow_version_statuses',
        'workgroup_members',
        'workgroups',
    ];

    /** Tables that may also hold rows belonging to the system itself. */
    private const array SYSTEM_OR_TENANT_OWNED = [
        'outbox_events',
        'security_audit_events',
    ];

    /**
     * `NULLIF` is doing real work here, not tidying. SQL does not promise to
     * short-circuit `OR`, so PostgreSQL is free to evaluate the cast even when
     * an earlier branch is already true — and casting an empty string to `uuid`
     * raises. Folding "never set" and "cleared" into one `NULL` means the cast
     * only ever sees a value that is meant to be an identifier.
     *
     * A non-empty value that is *not* a UUID still raises, on purpose: only the
     * application writes this setting, so a malformed one is a bug, and failing
     * loudly beats denying every row and looking like an empty database.
     */
    private const string SCOPE = "NULLIF(current_setting('sova.tenant_id', true), '')";

    private const string SCOPE_IS_UNSET = self::SCOPE . ' IS NULL';

    private const string ROW_BELONGS_TO_SCOPE = 'tenant_id = ' . self::SCOPE . '::uuid';

    public function getDescription(): string
    {
        return 'Enable row level security on every tenant-owned table, keyed on '
            . 'the sova.tenant_id session setting.';
    }

    public function up(Schema $schema): void
    {
        $scoped = sprintf('%s OR %s', self::SCOPE_IS_UNSET, self::ROW_BELONGS_TO_SCOPE);

        foreach (self::TENANT_OWNED as $table) {
            $this->enable($table);
            $this->addSql(sprintf(
                'CREATE POLICY tenant_isolation ON %s USING (%s) WITH CHECK (%s)',
                $table,
                $scoped,
                $scoped,
            ));
        }

        foreach (self::SYSTEM_OR_TENANT_OWNED as $table) {
            $this->enable($table);
            $this->addSql(sprintf(
                'CREATE POLICY tenant_isolation ON %s USING (%s) WITH CHECK (%s)',
                $table,
                $scoped,
                sprintf('%s OR tenant_id IS NULL', $scoped),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([...self::TENANT_OWNED, ...self::SYSTEM_OR_TENANT_OWNED] as $table) {
            $this->addSql(sprintf('DROP POLICY IF EXISTS tenant_isolation ON %s', $table));
            $this->addSql(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $table));
        }
    }

    private function enable(string $table): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
        // Without FORCE the owner — which is the application's own role — would
        // silently skip every policy below.
        $this->addSql(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));
    }
}
