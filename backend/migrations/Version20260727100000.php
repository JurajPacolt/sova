<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotent system tenant creation, lifecycle revision and owner invitations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
            ADD revision BIGINT NOT NULL DEFAULT 1,
            ADD deletion_requested_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD deletion_effective_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE tenants
            SET deletion_requested_at = CURRENT_TIMESTAMP,
                deletion_effective_at = CURRENT_TIMESTAMP + INTERVAL '30 days'
            WHERE status = 'DELETION_PENDING'
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
            ADD CONSTRAINT chk_tenants_revision CHECK (revision > 0),
            ADD CONSTRAINT chk_tenants_deletion_schedule CHECK (
                (
                    status = 'DELETION_PENDING'
                    AND deletion_requested_at IS NOT NULL
                    AND deletion_effective_at IS NOT NULL
                    AND deletion_effective_at >= deletion_requested_at + INTERVAL '30 days'
                )
                OR (
                    status <> 'DELETION_PENDING'
                    AND deletion_requested_at IS NULL
                    AND deletion_effective_at IS NULL
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_invitations
            ADD initial_role_code VARCHAR(64) DEFAULT NULL,
            ADD CONSTRAINT chk_tenant_invitations_initial_role CHECK (
                initial_role_code IS NULL
                OR initial_role_code = 'TENANT_OWNER'
            ),
            ADD CONSTRAINT fk_tenant_invitations_initial_role
                FOREIGN KEY (tenant_id, initial_role_code)
                REFERENCES tenant_roles (tenant_id, code)
                ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE system_tenant_creation_requests (
                actor_user_id UUID NOT NULL,
                idempotency_key UUID NOT NULL,
                request_fingerprint CHAR(64) NOT NULL,
                tenant_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_system_tenant_creation_requests
                    PRIMARY KEY (actor_user_id, idempotency_key),
                CONSTRAINT fk_system_tenant_creation_actor
                    FOREIGN KEY (actor_user_id)
                    REFERENCES users (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_system_tenant_creation_tenant
                    FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id)
                    ON DELETE RESTRICT,
                CONSTRAINT chk_system_tenant_creation_fingerprint CHECK (
                    request_fingerprint ~ '^[0-9a-f]{64}$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_system_tenant_creation_tenant
            ON system_tenant_creation_requests (tenant_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE system_tenant_creation_requests');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_invitations
            DROP CONSTRAINT fk_tenant_invitations_initial_role,
            DROP CONSTRAINT chk_tenant_invitations_initial_role,
            DROP COLUMN initial_role_code
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
            DROP CONSTRAINT chk_tenants_deletion_schedule,
            DROP CONSTRAINT chk_tenants_revision,
            DROP COLUMN deletion_effective_at,
            DROP COLUMN deletion_requested_at,
            DROP COLUMN revision
            SQL);
    }
}
