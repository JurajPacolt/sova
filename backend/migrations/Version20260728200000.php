<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved queries with explicit grants and personal favourites, '
            . 'and move the saved-query permissions from project to tenant scope.';
    }

    public function up(Schema $schema): void
    {
        // `saved-query.share` was project-scoped, but a saved query is a tenant
        // entity that may reference several projects at once — the permission
        // could never hang off one of them. The grant is therefore moved, not
        // duplicated: leaving the project row would keep an unreachable code on
        // project roles.
        $this->addSql(<<<'SQL'
            DELETE FROM project_role_permissions
            WHERE permission_code = 'saved-query.share'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO tenant_role_permissions (tenant_id, role_id, permission_code)
            SELECT tenant_id, id, code_to_grant
            FROM tenant_roles
            CROSS JOIN (
                VALUES ('saved-query.create'), ('saved-query.share')
            ) AS granted(code_to_grant)
            WHERE code IN ('TENANT_OWNER', 'TENANT_ADMIN', 'MEMBER')
            ON CONFLICT (tenant_id, role_id, permission_code) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO tenant_role_permissions (tenant_id, role_id, permission_code)
            SELECT tenant_id, id, 'saved-query.manage'
            FROM tenant_roles
            WHERE code IN ('TENANT_OWNER', 'TENANT_ADMIN')
            ON CONFLICT (tenant_id, role_id, permission_code) DO NOTHING
            SQL);

        // Only a valid query may be stored, and `canonical_query` is produced by
        // the server — the client never dictates it (spec §6.1).
        $this->addSql(<<<'SQL'
            CREATE TABLE saved_queries (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                owner_membership_id UUID NOT NULL,
                name VARCHAR(160) NOT NULL,
                normalized_name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                raw_query TEXT NOT NULL,
                canonical_query TEXT NOT NULL,
                language_version SMALLINT NOT NULL DEFAULT 1,
                default_columns JSONB NOT NULL DEFAULT '[]'::jsonb,
                visibility VARCHAR(16) NOT NULL DEFAULT 'PRIVATE',
                version BIGINT NOT NULL DEFAULT 1,
                archived_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_saved_queries PRIMARY KEY (id),
                CONSTRAINT uniq_saved_queries_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT chk_saved_queries_visibility
                    CHECK (visibility IN ('PRIVATE', 'SHARED')),
                CONSTRAINT chk_saved_queries_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_saved_queries_version CHECK (version >= 1),
                CONSTRAINT chk_saved_queries_columns
                    CHECK (jsonb_typeof(default_columns) = 'array'),
                CONSTRAINT fk_saved_queries_owner
                    FOREIGN KEY (tenant_id, owner_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);

        // The name is unique per owner among live queries; archiving frees it
        // again, which is why the index is partial.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_saved_queries_owner_name
                ON saved_queries (owner_membership_id, normalized_name)
                WHERE archived_at IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_saved_queries_tenant
                ON saved_queries (tenant_id, visibility)
                WHERE archived_at IS NULL
            SQL);

        // A grant names exactly one principal: a member or a workgroup, never
        // both and never neither.
        $this->addSql(<<<'SQL'
            CREATE TABLE saved_query_grants (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                saved_query_id UUID NOT NULL,
                membership_id UUID DEFAULT NULL,
                workgroup_id UUID DEFAULT NULL,
                access VARCHAR(8) NOT NULL,
                granted_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_saved_query_grants PRIMARY KEY (id),
                CONSTRAINT chk_saved_query_grants_access CHECK (access IN ('VIEW', 'EDIT')),
                CONSTRAINT chk_saved_query_grants_principal CHECK (
                    (membership_id IS NOT NULL AND workgroup_id IS NULL)
                    OR (membership_id IS NULL AND workgroup_id IS NOT NULL)
                ),
                CONSTRAINT fk_saved_query_grants_query
                    FOREIGN KEY (tenant_id, saved_query_id)
                    REFERENCES saved_queries (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_saved_query_grants_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_saved_query_grants_workgroup
                    FOREIGN KEY (tenant_id, workgroup_id)
                    REFERENCES workgroups (tenant_id, id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_saved_query_grants_member
                ON saved_query_grants (saved_query_id, membership_id)
                WHERE membership_id IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_saved_query_grants_workgroup
                ON saved_query_grants (saved_query_id, workgroup_id)
                WHERE workgroup_id IS NOT NULL
            SQL);

        // Favouriting is a personal link, not a property of the query itself
        // (spec §6.3), so it is keyed on the membership.
        $this->addSql(<<<'SQL'
            CREATE TABLE saved_query_favourites (
                tenant_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                saved_query_id UUID NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_saved_query_favourites
                    PRIMARY KEY (membership_id, saved_query_id),
                CONSTRAINT fk_saved_query_favourites_query
                    FOREIGN KEY (tenant_id, saved_query_id)
                    REFERENCES saved_queries (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_saved_query_favourites_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS saved_query_favourites');
        $this->addSql('DROP TABLE IF EXISTS saved_query_grants');
        $this->addSql('DROP TABLE IF EXISTS saved_queries');

        $this->addSql(<<<'SQL'
            DELETE FROM tenant_role_permissions
            WHERE permission_code IN (
                'saved-query.create', 'saved-query.share', 'saved-query.manage'
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO project_role_permissions (tenant_id, project_id, role_id, permission_code)
            SELECT tenant_id, project_id, id, 'saved-query.share'
            FROM project_roles
            WHERE code IN ('PROJECT_MANAGER', 'MEMBER')
            ON CONFLICT (project_id, role_id, permission_code) DO NOTHING
            SQL);
    }
}
