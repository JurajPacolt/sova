<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant workgroups and their members; wire authorization revision triggers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE workgroups (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                created_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_workgroups PRIMARY KEY (id),
                CONSTRAINT uniq_workgroups_tenant_id_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_workgroups_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_workgroups_created_by FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_workgroups_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_workgroups_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_workgroups_tenant_status
            ON workgroups (tenant_id, status, name)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE workgroup_members (
                tenant_id UUID NOT NULL,
                workgroup_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                member_role VARCHAR(16) NOT NULL DEFAULT 'MEMBER',
                added_by_user_id UUID DEFAULT NULL,
                joined_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_workgroup_members PRIMARY KEY (workgroup_id, membership_id),
                CONSTRAINT fk_workgroup_members_workgroup
                    FOREIGN KEY (tenant_id, workgroup_id)
                    REFERENCES workgroups (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_workgroup_members_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_workgroup_members_added_by
                    FOREIGN KEY (added_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_workgroup_members_role CHECK (
                    member_role IN ('MEMBER', 'MANAGER')
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_workgroup_members_membership
            ON workgroup_members (tenant_id, membership_id, workgroup_id)
            SQL);

        foreach (['workgroups', 'workgroup_members'] as $table) {
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
        $this->addSql(
            'DROP TRIGGER trg_workgroup_members_authorization_revision '
            . 'ON workgroup_members',
        );
        $this->addSql(
            'DROP TRIGGER trg_workgroups_authorization_revision ON workgroups',
        );
        $this->addSql('DROP TABLE workgroup_members');
        $this->addSql('DROP TABLE workgroups');
    }
}
