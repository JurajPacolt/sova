<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system roles and the append-only security audit foundation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE system_roles (
                code VARCHAR(64) NOT NULL,
                description VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_system_roles PRIMARY KEY (code),
                CONSTRAINT chk_system_roles_code CHECK (
                    code ~ '^[A-Z][A-Z0-9_]*$'
                ),
                CONSTRAINT chk_system_roles_description CHECK (
                    BTRIM(description) <> ''
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO system_roles (code, description)
            VALUES (
                'SUPERADMIN',
                'Unrestricted application access to the system and every tenant.'
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE user_system_roles (
                user_id UUID NOT NULL,
                role_code VARCHAR(64) NOT NULL,
                granted_by_user_id UUID DEFAULT NULL,
                granted_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_user_system_roles PRIMARY KEY (user_id, role_code),
                CONSTRAINT fk_user_system_roles_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_user_system_roles_role FOREIGN KEY (role_code)
                    REFERENCES system_roles (code) ON DELETE RESTRICT,
                CONSTRAINT fk_user_system_roles_granted_by FOREIGN KEY (granted_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_user_system_roles_role
            ON user_system_roles (role_code, user_id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE security_audit_events (
                id UUID NOT NULL,
                actor_user_id UUID NOT NULL,
                effective_user_id UUID DEFAULT NULL,
                tenant_id UUID DEFAULT NULL,
                event_type VARCHAR(64) NOT NULL,
                outcome VARCHAR(16) NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                request_id VARCHAR(128) NOT NULL,
                ip_address INET DEFAULT NULL,
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_security_audit_events PRIMARY KEY (id),
                CONSTRAINT fk_security_audit_events_actor FOREIGN KEY (actor_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_security_audit_events_effective_user
                    FOREIGN KEY (effective_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_security_audit_events_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE RESTRICT,
                CONSTRAINT chk_security_audit_events_type CHECK (
                    event_type ~ '^[A-Z][A-Z0-9_]*$'
                ),
                CONSTRAINT chk_security_audit_events_outcome CHECK (
                    outcome IN ('SUCCESS', 'FAILURE')
                ),
                CONSTRAINT chk_security_audit_events_reason CHECK (
                    reason_code ~ '^[A-Z][A-Z0-9_]*$'
                ),
                CONSTRAINT chk_security_audit_events_request_id CHECK (
                    request_id ~ '^[A-Za-z0-9._-]{8,128}$'
                ),
                CONSTRAINT chk_security_audit_events_metadata CHECK (
                    jsonb_typeof(metadata) = 'object'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_security_audit_events_tenant_occurred
            ON security_audit_events (tenant_id, occurred_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_security_audit_events_actor_occurred
            ON security_audit_events (actor_user_id, occurred_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_security_audit_events_occurred
            ON security_audit_events (occurred_at DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE security_audit_events');
        $this->addSql('DROP TABLE user_system_roles');
        $this->addSql('DROP TABLE system_roles');
    }
}

