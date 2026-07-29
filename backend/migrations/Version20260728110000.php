<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the project configuration revision used as the publishing '
            . 'optimistic lock and cache key, plus the workflow transition rule '
            . 'register and the configuration history log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE project_configuration_revisions (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_configuration_revisions PRIMARY KEY (project_id),
                CONSTRAINT uniq_project_configuration_revisions_tenant_project
                    UNIQUE (tenant_id, project_id),
                CONSTRAINT fk_project_configuration_revisions_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_project_configuration_revisions_positive
                    CHECK (revision > 0)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO project_configuration_revisions (tenant_id, project_id)
            SELECT tenant_id, id
            FROM projects
            ON CONFLICT (project_id) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_transition_rules (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                transition_id UUID NOT NULL,
                rule_type VARCHAR(16) NOT NULL,
                rule_key VARCHAR(64) NOT NULL,
                configuration JSONB NOT NULL DEFAULT '{}'::jsonb,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_workflow_transition_rules PRIMARY KEY (id),
                CONSTRAINT uniq_workflow_transition_rules_key
                    UNIQUE (transition_id, rule_type, rule_key),
                CONSTRAINT fk_workflow_transition_rules_transition
                    FOREIGN KEY (tenant_id, project_id, transition_id)
                    REFERENCES project_workflow_transitions (tenant_id, project_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_workflow_transition_rules_type CHECK (
                    rule_type IN ('CONDITION', 'VALIDATOR', 'ACTION')
                ),
                CONSTRAINT chk_workflow_transition_rules_key CHECK (
                    rule_key ~ '^[a-z][a-z0-9_]*$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_workflow_transition_rules_transition
            ON workflow_transition_rules (transition_id, position)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE project_configuration_history (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                revision BIGINT NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                workflow_id UUID DEFAULT NULL,
                workflow_version_id UUID DEFAULT NULL,
                actor_user_id UUID DEFAULT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_project_configuration_history PRIMARY KEY (id),
                CONSTRAINT fk_project_configuration_history_project
                    FOREIGN KEY (tenant_id, project_id)
                    REFERENCES projects (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_project_configuration_history_actor
                    FOREIGN KEY (actor_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_project_configuration_history_event CHECK (
                    event_type ~ '^[A-Z][A-Z0-9_]*$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_project_configuration_history_project
            ON project_configuration_history (
                tenant_id,
                project_id,
                created_at DESC
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_configuration_history');
        $this->addSql('DROP TABLE workflow_transition_rules');
        $this->addSql('DROP TABLE project_configuration_revisions');
    }
}
