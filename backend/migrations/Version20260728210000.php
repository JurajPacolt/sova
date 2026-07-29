<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personal dashboards, their widgets and the per-membership '
            . 'active dashboard preference.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_role_permissions (tenant_id, role_id, permission_code)
            SELECT tenant_id, id, code_to_grant
            FROM tenant_roles
            CROSS JOIN (
                VALUES
                    ('dashboard.create'),
                    ('dashboard.update-own'),
                    ('dashboard.delete-own')
            ) AS granted(code_to_grant)
            WHERE code IN ('TENANT_OWNER', 'TENANT_ADMIN', 'MEMBER', 'VIEWER')
            ON CONFLICT (tenant_id, role_id, permission_code) DO NOTHING
            SQL);

        // A dashboard belongs to exactly one membership in exactly one tenant.
        // Team or tenant-wide dashboards are a future extension and must not be
        // faked by moving `owner_membership_id` (spec §7.1).
        $this->addSql(<<<'SQL'
            CREATE TABLE dashboards (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                owner_membership_id UUID NOT NULL,
                name VARCHAR(160) NOT NULL,
                normalized_name VARCHAR(160) NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                version BIGINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_dashboards PRIMARY KEY (id),
                CONSTRAINT uniq_dashboards_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT chk_dashboards_name CHECK (btrim(name) <> ''),
                CONSTRAINT chk_dashboards_version CHECK (version >= 1),
                CONSTRAINT chk_dashboards_position CHECK (position >= 0),
                CONSTRAINT fk_dashboards_owner
                    FOREIGN KEY (tenant_id, owner_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE
            )
            SQL);

        // Unlike a saved query, a dashboard has no archived state, so the name
        // is simply unique per owner.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_dashboards_owner_name
                ON dashboards (owner_membership_id, normalized_name)
            SQL);

        // Exactly one default per membership, enforced by the database rather
        // than by whoever remembers to clear the old one.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_dashboards_owner_default
                ON dashboards (owner_membership_id)
                WHERE is_default
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_dashboards_owner_order
                ON dashboards (owner_membership_id, position, id)
            SQL);

        // A widget renders a saved query and nothing else: there is no inline
        // SovaQL, no SQL and no executable configuration (spec §8.1). The
        // source is RESTRICTed rather than cascaded, which is what makes
        // `SAVED_QUERY_IN_USE` truthful.
        $this->addSql(<<<'SQL'
            CREATE TABLE dashboard_widgets (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                dashboard_id UUID NOT NULL,
                saved_query_id UUID NOT NULL,
                type_key VARCHAR(64) NOT NULL,
                schema_version SMALLINT NOT NULL DEFAULT 1,
                title VARCHAR(160) NOT NULL DEFAULT '',
                configuration JSONB NOT NULL DEFAULT '{}'::jsonb,
                x SMALLINT NOT NULL,
                y SMALLINT NOT NULL,
                width SMALLINT NOT NULL,
                height SMALLINT NOT NULL,
                version BIGINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_dashboard_widgets PRIMARY KEY (id),
                CONSTRAINT uniq_dashboard_widgets_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT chk_dashboard_widgets_version CHECK (version >= 1),
                CONSTRAINT chk_dashboard_widgets_schema CHECK (schema_version >= 1),
                CONSTRAINT chk_dashboard_widgets_configuration
                    CHECK (jsonb_typeof(configuration) = 'object'),
                -- The 12-column grid: a widget starts inside it and does not
                -- reach past its right edge.
                CONSTRAINT chk_dashboard_widgets_bounds CHECK (
                    x >= 0 AND y >= 0
                    AND width BETWEEN 1 AND 12
                    AND height BETWEEN 1 AND 12
                    AND x + width <= 12
                ),
                CONSTRAINT fk_dashboard_widgets_dashboard
                    FOREIGN KEY (tenant_id, dashboard_id)
                    REFERENCES dashboards (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_dashboard_widgets_saved_query
                    FOREIGN KEY (tenant_id, saved_query_id)
                    REFERENCES saved_queries (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);

        // Stable order is y, then x, then id (spec §7.4), which is also how the
        // mobile single column is laid out.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_dashboard_widgets_order
                ON dashboard_widgets (dashboard_id, y, x, id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_dashboard_widgets_source
                ON dashboard_widgets (tenant_id, saved_query_id)
            SQL);

        // The last dashboard somebody looked at is a personal preference per
        // tenant, not a property of the dashboard.
        $this->addSql(<<<'SQL'
            CREATE TABLE membership_dashboard_preferences (
                tenant_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                active_dashboard_id UUID DEFAULT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_membership_dashboard_preferences PRIMARY KEY (membership_id),
                CONSTRAINT fk_membership_dashboard_preferences_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE,
                -- A deleted dashboard clears the preference instead of leaving
                -- a pointer to nothing. Only the dashboard column is cleared:
                -- nulling the whole composite key would violate the NOT NULL on
                -- `tenant_id` (PostgreSQL 15+ column list).
                CONSTRAINT fk_membership_dashboard_preferences_dashboard
                    FOREIGN KEY (tenant_id, active_dashboard_id)
                    REFERENCES dashboards (tenant_id, id)
                    ON DELETE SET NULL (active_dashboard_id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS membership_dashboard_preferences');
        $this->addSql('DROP TABLE IF EXISTS dashboard_widgets');
        $this->addSql('DROP TABLE IF EXISTS dashboards');

        $this->addSql(<<<'SQL'
            DELETE FROM tenant_role_permissions
            WHERE permission_code IN (
                'dashboard.create', 'dashboard.update-own', 'dashboard.delete-own'
            )
            SQL);
    }
}
