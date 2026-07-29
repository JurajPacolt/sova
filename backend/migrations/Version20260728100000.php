<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project issue types, statuses, versioned workflows, '
            . 'issues with an atomic project number and issue history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE project_issue_types (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                hierarchy_level SMALLINT NOT NULL DEFAULT 0,
                position INTEGER NOT NULL DEFAULT 0,
                icon VARCHAR(48) NOT NULL DEFAULT '',
                color_token VARCHAR(48) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                version BIGINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_issue_types PRIMARY KEY (id),
                CONSTRAINT uniq_project_issue_types_tenant_project_id
                    UNIQUE (tenant_id, project_id, id),
                CONSTRAINT uniq_project_issue_types_project_code
                    UNIQUE (project_id, code),
                CONSTRAINT fk_project_issue_types_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_issue_types_code CHECK (
                    code ~ '^[A-Z][A-Z0-9_]{1,31}$'
                ),
                CONSTRAINT chk_project_issue_types_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_project_issue_types_level CHECK (
                    hierarchy_level IN (-1, 0, 1)
                ),
                CONSTRAINT chk_project_issue_types_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_statuses (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                category VARCHAR(16) NOT NULL,
                color_token VARCHAR(48) NOT NULL DEFAULT '',
                position INTEGER NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_statuses PRIMARY KEY (id),
                CONSTRAINT uniq_project_statuses_tenant_project_id
                    UNIQUE (tenant_id, project_id, id),
                CONSTRAINT uniq_project_statuses_project_code
                    UNIQUE (project_id, code),
                CONSTRAINT fk_project_statuses_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_statuses_code CHECK (
                    code ~ '^[A-Z][A-Z0-9_]{1,31}$'
                ),
                CONSTRAINT chk_project_statuses_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_project_statuses_category CHECK (
                    category IN ('TO_DO', 'IN_PROGRESS', 'DONE')
                ),
                CONSTRAINT chk_project_statuses_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_workflows (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                active_version_id UUID DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_workflows PRIMARY KEY (id),
                CONSTRAINT uniq_project_workflows_tenant_project_id
                    UNIQUE (tenant_id, project_id, id),
                CONSTRAINT fk_project_workflows_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_workflows_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_project_workflows_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_workflow_versions (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                workflow_id UUID NOT NULL,
                version_number INTEGER NOT NULL,
                state VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
                initial_status_id UUID DEFAULT NULL,
                version BIGINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                published_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                CONSTRAINT pk_project_workflow_versions PRIMARY KEY (id),
                CONSTRAINT uniq_project_workflow_versions_tenant_project_id
                    UNIQUE (tenant_id, project_id, id),
                CONSTRAINT uniq_project_workflow_versions_number
                    UNIQUE (workflow_id, version_number),
                CONSTRAINT fk_project_workflow_versions_workflow
                    FOREIGN KEY (tenant_id, project_id, workflow_id)
                    REFERENCES project_workflows (tenant_id, project_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_project_workflow_versions_initial_status
                    FOREIGN KEY (tenant_id, project_id, initial_status_id)
                    REFERENCES project_statuses (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT chk_project_workflow_versions_number CHECK (
                    version_number >= 1
                ),
                CONSTRAINT chk_project_workflow_versions_state CHECK (
                    state IN ('DRAFT', 'PUBLISHED', 'RETIRED')
                ),
                CONSTRAINT chk_project_workflow_versions_published CHECK (
                    state <> 'PUBLISHED'
                    OR (initial_status_id IS NOT NULL AND published_at IS NOT NULL)
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_project_workflow_versions_single_draft
            ON project_workflow_versions (workflow_id)
            WHERE state = 'DRAFT'
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE project_workflows
            ADD CONSTRAINT fk_project_workflows_active_version
                FOREIGN KEY (tenant_id, project_id, active_version_id)
                REFERENCES project_workflow_versions (tenant_id, project_id, id)
                ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_version_statuses (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                workflow_version_id UUID NOT NULL,
                status_id UUID NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                CONSTRAINT pk_workflow_version_statuses
                    PRIMARY KEY (workflow_version_id, status_id),
                CONSTRAINT fk_workflow_version_statuses_version
                    FOREIGN KEY (tenant_id, project_id, workflow_version_id)
                    REFERENCES project_workflow_versions (tenant_id, project_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_workflow_version_statuses_status
                    FOREIGN KEY (tenant_id, project_id, status_id)
                    REFERENCES project_statuses (tenant_id, project_id, id)
                    ON DELETE RESTRICT
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_workflow_transitions (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                workflow_version_id UUID NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(120) NOT NULL,
                from_status_id UUID NOT NULL,
                to_status_id UUID NOT NULL,
                permission_code VARCHAR(128) DEFAULT NULL,
                is_primary BOOLEAN NOT NULL DEFAULT FALSE,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_workflow_transitions PRIMARY KEY (id),
                CONSTRAINT uniq_project_workflow_transitions_tenant_project_id
                    UNIQUE (tenant_id, project_id, id),
                CONSTRAINT uniq_project_workflow_transitions_code
                    UNIQUE (workflow_version_id, code),
                CONSTRAINT fk_project_workflow_transitions_version
                    FOREIGN KEY (tenant_id, project_id, workflow_version_id)
                    REFERENCES project_workflow_versions (tenant_id, project_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_project_workflow_transitions_from_status
                    FOREIGN KEY (tenant_id, project_id, from_status_id)
                    REFERENCES project_statuses (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_project_workflow_transitions_to_status
                    FOREIGN KEY (tenant_id, project_id, to_status_id)
                    REFERENCES project_statuses (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT chk_project_workflow_transitions_code CHECK (
                    code ~ '^[A-Z][A-Z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_project_workflow_transitions_name CHECK (
                    BTRIM(name) <> ''
                ),
                CONSTRAINT chk_project_workflow_transitions_self CHECK (
                    from_status_id <> to_status_id
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_project_workflow_transitions_source
            ON project_workflow_transitions (
                workflow_version_id,
                from_status_id,
                position
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_issue_type_workflows (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                issue_type_id UUID NOT NULL,
                workflow_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_issue_type_workflows
                    PRIMARY KEY (project_id, issue_type_id),
                CONSTRAINT fk_project_issue_type_workflows_type
                    FOREIGN KEY (tenant_id, project_id, issue_type_id)
                    REFERENCES project_issue_types (tenant_id, project_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_project_issue_type_workflows_workflow
                    FOREIGN KEY (tenant_id, project_id, workflow_id)
                    REFERENCES project_workflows (tenant_id, project_id, id)
                    ON DELETE RESTRICT
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_issue_counters (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                next_number BIGINT NOT NULL DEFAULT 1,
                CONSTRAINT pk_project_issue_counters PRIMARY KEY (project_id),
                CONSTRAINT fk_project_issue_counters_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_issue_counters_next CHECK (next_number >= 1)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE issues (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                number BIGINT NOT NULL,
                issue_key VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                issue_type_id UUID NOT NULL,
                workflow_version_id UUID NOT NULL,
                status_id UUID NOT NULL,
                parent_issue_id UUID DEFAULT NULL,
                reporter_membership_id UUID NOT NULL,
                assignee_membership_id UUID DEFAULT NULL,
                assignee_workgroup_id UUID DEFAULT NULL,
                priority VARCHAR(16) NOT NULL DEFAULT 'NORMAL',
                version BIGINT NOT NULL DEFAULT 1,
                created_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_issues PRIMARY KEY (id),
                CONSTRAINT uniq_issues_tenant_project_id UNIQUE (tenant_id, project_id, id),
                CONSTRAINT uniq_issues_project_number UNIQUE (project_id, number),
                CONSTRAINT uniq_issues_project_key UNIQUE (project_id, issue_key),
                CONSTRAINT fk_issues_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issues_type
                    FOREIGN KEY (tenant_id, project_id, issue_type_id)
                    REFERENCES project_issue_types (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_issues_workflow_version
                    FOREIGN KEY (tenant_id, project_id, workflow_version_id)
                    REFERENCES project_workflow_versions (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_issues_status
                    FOREIGN KEY (tenant_id, project_id, status_id)
                    REFERENCES project_statuses (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_issues_parent
                    FOREIGN KEY (tenant_id, project_id, parent_issue_id)
                    REFERENCES issues (tenant_id, project_id, id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_issues_reporter
                    FOREIGN KEY (tenant_id, reporter_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_issues_assignee
                    FOREIGN KEY (tenant_id, assignee_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_issues_assignee_workgroup
                    FOREIGN KEY (tenant_id, assignee_workgroup_id)
                    REFERENCES workgroups (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_issues_created_by FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_issues_number CHECK (number >= 1),
                CONSTRAINT chk_issues_title CHECK (BTRIM(title) <> ''),
                CONSTRAINT chk_issues_priority CHECK (
                    priority IN ('LOW', 'NORMAL', 'HIGH', 'CRITICAL')
                ),
                CONSTRAINT chk_issues_parent_not_self CHECK (
                    parent_issue_id IS NULL OR parent_issue_id <> id
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_status
            ON issues (tenant_id, project_id, status_id, number DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_updated
            ON issues (tenant_id, project_id, updated_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_parent
            ON issues (tenant_id, project_id, parent_issue_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_assignee
            ON issues (tenant_id, assignee_membership_id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_history (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                issue_id UUID NOT NULL,
                issue_version BIGINT NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                actor_user_id UUID DEFAULT NULL,
                transition_id UUID DEFAULT NULL,
                from_status_id UUID DEFAULT NULL,
                to_status_id UUID DEFAULT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_issue_history PRIMARY KEY (id),
                CONSTRAINT uniq_issue_history_issue_version
                    UNIQUE (issue_id, issue_version),
                CONSTRAINT fk_issue_history_issue
                    FOREIGN KEY (tenant_id, project_id, issue_id)
                    REFERENCES issues (tenant_id, project_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_history_actor FOREIGN KEY (actor_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_issue_history_event CHECK (
                    event_type ~ '^[A-Z][A-Z0-9_]*$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_history_issue
            ON issue_history (issue_id, created_at DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE issue_history');
        $this->addSql('DROP TABLE issues');
        $this->addSql('DROP TABLE project_issue_counters');
        $this->addSql('DROP TABLE project_issue_type_workflows');
        $this->addSql('DROP TABLE project_workflow_transitions');
        $this->addSql('DROP TABLE workflow_version_statuses');
        $this->addSql(
            'ALTER TABLE project_workflows DROP CONSTRAINT fk_project_workflows_active_version',
        );
        $this->addSql('DROP TABLE project_workflow_versions');
        $this->addSql('DROP TABLE project_workflows');
        $this->addSql('DROP TABLE project_statuses');
        $this->addSql('DROP TABLE project_issue_types');
    }
}
