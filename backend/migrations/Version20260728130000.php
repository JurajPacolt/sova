<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill the issue.change-type permission into the default tenant '
            . 'and project roles that receive it, and invalidate cached '
            . 'authorization decisions.';
    }

    public function up(Schema $schema): void
    {
        // Tenant-scope system roles that now include issue.change-type.
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_role_permissions (tenant_id, role_id, permission_code)
            SELECT tenant_id, id, 'issue.change-type'
            FROM tenant_roles
            WHERE code IN ('TENANT_OWNER', 'TENANT_ADMIN', 'MEMBER')
            ON CONFLICT (tenant_id, role_id, permission_code) DO NOTHING
            SQL);

        // Project-scope roles that now include issue.change-type.
        $this->addSql(<<<'SQL'
            INSERT INTO project_role_permissions (tenant_id, project_id, role_id, permission_code)
            SELECT tenant_id, project_id, id, 'issue.change-type'
            FROM project_roles
            WHERE code IN ('PROJECT_MANAGER', 'MEMBER')
            ON CONFLICT (project_id, role_id, permission_code) DO NOTHING
            SQL);

        // Force every tenant's cached effective permissions to be recomputed.
        $this->addSql(<<<'SQL'
            UPDATE tenant_authorization_revisions
            SET revision = revision + 1,
                updated_at = CURRENT_TIMESTAMP
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM project_role_permissions
            WHERE permission_code = 'issue.change-type'
            SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM tenant_role_permissions
            WHERE permission_code = 'issue.change-type'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE tenant_authorization_revisions
            SET revision = revision + 1,
                updated_at = CURRENT_TIMESTAMP
            SQL);
    }
}
