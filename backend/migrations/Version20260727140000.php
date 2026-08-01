<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create projects, project roles, and their membership/workgroup access; '
            . 'wire authorization revision triggers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE projects (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                code VARCHAR(10) NOT NULL,
                name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                visibility VARCHAR(16) NOT NULL DEFAULT 'TENANT',
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                lead_membership_id UUID DEFAULT NULL,
                created_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_projects PRIMARY KEY (id),
                CONSTRAINT uniq_projects_tenant_id_id UNIQUE (tenant_id, id),
                CONSTRAINT uniq_projects_tenant_code UNIQUE (tenant_id, code),
                CONSTRAINT fk_projects_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_projects_lead_membership
                    FOREIGN KEY (tenant_id, lead_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_projects_code CHECK (
                    code ~ '^[A-Z][A-Z0-9]{1,9}$'
                ),
                CONSTRAINT chk_projects_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_projects_visibility CHECK (
                    visibility IN ('TENANT', 'PRIVATE')
                ),
                CONSTRAINT chk_projects_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_projects_tenant_status
            ON projects (tenant_id, status, name)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_roles (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                is_system BOOLEAN NOT NULL DEFAULT TRUE,
                is_editable BOOLEAN NOT NULL DEFAULT FALSE,
                revision BIGINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_roles PRIMARY KEY (id),
                CONSTRAINT uniq_project_roles_tenant_project_id
                    UNIQUE (tenant_id, project_id, id),
                CONSTRAINT uniq_project_roles_project_code UNIQUE (project_id, code),
                CONSTRAINT fk_project_roles_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_roles_code CHECK (
                    code ~ '^[A-Z][A-Z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_project_roles_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_project_roles_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_role_permissions (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                role_id UUID NOT NULL,
                permission_code VARCHAR(128) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_role_permissions
                    PRIMARY KEY (project_id, role_id, permission_code),
                CONSTRAINT fk_project_role_permissions_role
                    FOREIGN KEY (tenant_id, project_id, role_id)
                    REFERENCES project_roles (tenant_id, project_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_role_permissions_code CHECK (
                    permission_code
                        ~ '^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_project_role_permissions_lookup
            ON project_role_permissions (
                project_id,
                permission_code,
                role_id
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_membership_role_assignments (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                role_id UUID NOT NULL,
                granted_by_user_id UUID DEFAULT NULL,
                granted_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_membership_role_assignments
                    PRIMARY KEY (project_id, membership_id, role_id),
                CONSTRAINT fk_project_membership_role_assignments_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_membership_role_assignments_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_membership_role_assignments_role
                    FOREIGN KEY (tenant_id, project_id, role_id)
                    REFERENCES project_roles (tenant_id, project_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_membership_role_assignments_granted_by
                    FOREIGN KEY (granted_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_project_membership_role_assignments_membership
            ON project_membership_role_assignments (
                tenant_id,
                membership_id,
                project_id
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_workgroups (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                workgroup_id UUID NOT NULL,
                role_id UUID NOT NULL,
                added_by_user_id UUID DEFAULT NULL,
                added_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_workgroups PRIMARY KEY (project_id, workgroup_id),
                CONSTRAINT fk_project_workgroups_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_workgroups_workgroup
                    FOREIGN KEY (tenant_id, workgroup_id)
                    REFERENCES workgroups (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_workgroups_role
                    FOREIGN KEY (tenant_id, project_id, role_id)
                    REFERENCES project_roles (tenant_id, project_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_workgroups_added_by
                    FOREIGN KEY (added_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_project_workgroups_workgroup
            ON project_workgroups (tenant_id, workgroup_id, project_id)
            SQL);

        foreach ([
            'projects',
            'project_roles',
            'project_role_permissions',
            'project_membership_role_assignments',
            'project_workgroups',
        ] as $table) {
            $this->addSql(sprintf(
                <<<'SQL'
                    CREATE TRIGGER trg_%1$s_authorization_revision
                    AFTER INSERT OR UPDATE OR DELETE ON %1$s
                    FOR EACH ROW
                    EXECUTE FUNCTION sova_bump_tenant_authorization_revision()
                    SQL,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (
            [
                'project_workgroups',
                'project_membership_role_assignments',
                'project_role_permissions',
                'project_roles',
                'projects',
            ] as $table
        ) {
            $this->addSql(sprintf(
                'DROP TRIGGER trg_%1$s_authorization_revision ON %1$s',
                $table,
            ));
        }

        $this->addSql('DROP TABLE project_workgroups');
        $this->addSql('DROP TABLE project_membership_role_assignments');
        $this->addSql('DROP TABLE project_role_permissions');
        $this->addSql('DROP TABLE project_roles');
        $this->addSql('DROP TABLE projects');
    }
}
