<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;
use Sova\Shared\Domain\ValueObject\UuidV7;

final class Version20260726200000 extends AbstractMigration
{
    /**
     * Immutable snapshot of the F3.1 default tenant-role matrix.
     *
     * @var array<string, list<string>>
     */
    private const TENANT_ROLE_PERMISSIONS = [
        'TENANT_OWNER' => [
            'tenant.view',
            'tenant.settings.manage',
            'tenant.members.view',
            'tenant.members.invite',
            'tenant.members.manage',
            'tenant.roles.view',
            'tenant.roles.manage',
            'tenant.roles.assign',
            'tenant.workgroups.manage',
            'tenant.projects.create',
            'tenant.projects.manage',
            'tenant.audit.view',
            'tenant.audit.export',
            'project.view',
            'project.settings.manage',
            'project.members.manage',
            'project.workflow.manage',
            'project.workflow.publish',
            'issue.view',
            'issue.create',
            'issue.edit',
            'issue.assign',
            'issue.transition',
            'issue.delete',
            'comment.create',
            'comment.moderate',
            'attachment.upload',
            'attachment.moderate',
            'saved-query.share',
            'workgroup.view',
            'workgroup.manage',
            'workgroup.members.manage',
        ],
        'TENANT_ADMIN' => [
            'tenant.view',
            'tenant.settings.manage',
            'tenant.members.view',
            'tenant.members.invite',
            'tenant.members.manage',
            'tenant.roles.view',
            'tenant.roles.assign',
            'tenant.workgroups.manage',
            'tenant.projects.create',
            'tenant.projects.manage',
            'tenant.audit.view',
            'project.view',
            'project.settings.manage',
            'project.members.manage',
            'project.workflow.manage',
            'project.workflow.publish',
            'issue.view',
            'issue.create',
            'issue.edit',
            'issue.assign',
            'issue.transition',
            'comment.create',
            'comment.moderate',
            'attachment.upload',
            'attachment.moderate',
            'saved-query.share',
            'workgroup.view',
            'workgroup.manage',
            'workgroup.members.manage',
        ],
        'MEMBER' => [
            'tenant.view',
            'tenant.members.view',
            'project.view',
            'issue.view',
            'issue.create',
            'issue.edit',
            'issue.assign',
            'issue.transition',
            'comment.create',
            'attachment.upload',
            'saved-query.share',
        ],
        'VIEWER' => [
            'tenant.view',
            'tenant.members.view',
            'project.view',
            'issue.view',
        ],
    ];

    private const ROLE_NAMES = [
        'TENANT_OWNER' => 'Tenant owner',
        'TENANT_ADMIN' => 'Tenant administrator',
        'MEMBER' => 'Member',
        'VIEWER' => 'Viewer',
    ];

    public function getDescription(): string
    {
        return 'Create tenant roles, permission grants, assignments, and authorization revisions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_authorization_revisions (
                tenant_id UUID NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenant_authorization_revisions PRIMARY KEY (tenant_id),
                CONSTRAINT fk_tenant_authorization_revisions_tenant
                    FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT chk_tenant_authorization_revisions_positive
                    CHECK (revision > 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO tenant_authorization_revisions (tenant_id)
            SELECT id
            FROM tenants
            ON CONFLICT (tenant_id) DO NOTHING
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_roles (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                is_system BOOLEAN NOT NULL DEFAULT FALSE,
                is_editable BOOLEAN NOT NULL DEFAULT TRUE,
                revision BIGINT NOT NULL DEFAULT 1,
                created_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenant_roles PRIMARY KEY (id),
                CONSTRAINT uniq_tenant_roles_tenant_id_id UNIQUE (tenant_id, id),
                CONSTRAINT uniq_tenant_roles_tenant_code UNIQUE (tenant_id, code),
                CONSTRAINT fk_tenant_roles_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_tenant_roles_created_by FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_tenant_roles_code CHECK (
                    code ~ '^[A-Z][A-Z0-9_]{1,63}$'
                ),
                CONSTRAINT chk_tenant_roles_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_tenant_roles_status CHECK (
                    status IN ('ACTIVE', 'ARCHIVED')
                ),
                CONSTRAINT chk_tenant_roles_system_immutable CHECK (
                    NOT is_system OR NOT is_editable
                ),
                CONSTRAINT chk_tenant_roles_revision CHECK (revision > 0),
                CONSTRAINT chk_tenant_roles_timestamps CHECK (
                    updated_at >= created_at
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_tenant_roles_tenant_status
            ON tenant_roles (tenant_id, status, code)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_role_permissions (
                tenant_id UUID NOT NULL,
                role_id UUID NOT NULL,
                permission_code VARCHAR(128) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenant_role_permissions
                    PRIMARY KEY (tenant_id, role_id, permission_code),
                CONSTRAINT fk_tenant_role_permissions_role
                    FOREIGN KEY (tenant_id, role_id)
                    REFERENCES tenant_roles (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT chk_tenant_role_permissions_code CHECK (
                    permission_code
                        ~ '^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_tenant_role_permissions_lookup
            ON tenant_role_permissions (
                tenant_id,
                permission_code,
                role_id
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_membership_role_assignments (
                tenant_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                role_id UUID NOT NULL,
                granted_by_user_id UUID DEFAULT NULL,
                granted_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenant_membership_role_assignments
                    PRIMARY KEY (tenant_id, membership_id, role_id),
                CONSTRAINT fk_tenant_membership_role_assignments_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_tenant_membership_role_assignments_role
                    FOREIGN KEY (tenant_id, role_id)
                    REFERENCES tenant_roles (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_tenant_membership_role_assignments_granted_by
                    FOREIGN KEY (granted_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_tenant_membership_role_assignments_role
            ON tenant_membership_role_assignments (
                tenant_id,
                role_id,
                membership_id
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION sova_bump_tenant_authorization_revision()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                affected_tenant_id UUID;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    affected_tenant_id := OLD.tenant_id;
                ELSE
                    affected_tenant_id := NEW.tenant_id;
                END IF;

                INSERT INTO tenant_authorization_revisions (
                    tenant_id,
                    revision,
                    updated_at
                )
                VALUES (
                    affected_tenant_id,
                    1,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (tenant_id) DO UPDATE
                SET revision = tenant_authorization_revisions.revision + 1,
                    updated_at = CURRENT_TIMESTAMP;

                RETURN NULL;
            END;
            $$
            SQL);

        foreach ([
            'tenant_memberships',
            'tenant_roles',
            'tenant_role_permissions',
            'tenant_membership_role_assignments',
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

        $this->addSql(<<<'SQL'
            CREATE FUNCTION sova_bump_tenant_status_authorization_revision()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                INSERT INTO tenant_authorization_revisions (
                    tenant_id,
                    revision,
                    updated_at
                )
                VALUES (
                    NEW.id,
                    1,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (tenant_id) DO UPDATE
                SET revision = tenant_authorization_revisions.revision + 1,
                    updated_at = CURRENT_TIMESTAMP;

                RETURN NULL;
            END;
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_tenants_authorization_revision
            AFTER UPDATE OF status ON tenants
            FOR EACH ROW
            WHEN (OLD.status IS DISTINCT FROM NEW.status)
            EXECUTE FUNCTION sova_bump_tenant_status_authorization_revision()
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION sova_bump_user_authorization_revisions()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                UPDATE tenant_authorization_revisions revision
                SET revision = revision.revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE revision.tenant_id IN (
                    SELECT membership.tenant_id
                    FROM tenant_memberships membership
                    WHERE membership.user_id = NEW.id
                );

                RETURN NULL;
            END;
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_users_authorization_revision
            AFTER UPDATE OF status ON users
            FOR EACH ROW
            WHEN (OLD.status IS DISTINCT FROM NEW.status)
            EXECUTE FUNCTION sova_bump_user_authorization_revisions()
            SQL);

        $tenantIds = $this->connection->fetchFirstColumn(
            'SELECT id::text FROM tenants ORDER BY id',
        );

        foreach ($tenantIds as $tenantId) {
            if (!is_string($tenantId)) {
                throw new RuntimeException(
                    'Expected every existing tenant identifier to be a string.',
                );
            }

            $this->seedDefaultRoles($tenantId);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP TRIGGER trg_users_authorization_revision ON users',
        );
        $this->addSql(
            'DROP TRIGGER trg_tenants_authorization_revision ON tenants',
        );
        $this->addSql(
            'DROP TRIGGER trg_tenant_memberships_authorization_revision '
            . 'ON tenant_memberships',
        );
        $this->addSql(
            'DROP TABLE tenant_membership_role_assignments',
        );
        $this->addSql('DROP TABLE tenant_role_permissions');
        $this->addSql('DROP TABLE tenant_roles');
        $this->addSql(
            'DROP FUNCTION sova_bump_user_authorization_revisions()',
        );
        $this->addSql(
            'DROP FUNCTION sova_bump_tenant_status_authorization_revision()',
        );
        $this->addSql(
            'DROP FUNCTION sova_bump_tenant_authorization_revision()',
        );
        $this->addSql('DROP TABLE tenant_authorization_revisions');
    }

    private function seedDefaultRoles(string $tenantId): void
    {
        foreach (self::TENANT_ROLE_PERMISSIONS as $roleCode => $permissions) {
            $roleId = (string) UuidV7::generate();
            $this->addSql(
                <<<'SQL'
                    INSERT INTO tenant_roles (
                        id,
                        tenant_id,
                        code,
                        name,
                        description,
                        is_system,
                        is_editable
                    )
                    VALUES (
                        :id,
                        :tenant_id,
                        :code,
                        :name,
                        :description,
                        TRUE,
                        FALSE
                    )
                    SQL,
                [
                    'id' => $roleId,
                    'tenant_id' => $tenantId,
                    'code' => $roleCode,
                    'name' => self::ROLE_NAMES[$roleCode],
                    'description' => sprintf(
                        'Immutable SOVA default role %s.',
                        $roleCode,
                    ),
                ],
            );

            foreach ($permissions as $permission) {
                $this->addSql(
                    <<<'SQL'
                        INSERT INTO tenant_role_permissions (
                            tenant_id,
                            role_id,
                            permission_code
                        )
                        VALUES (
                            :tenant_id,
                            :role_id,
                            :permission_code
                        )
                        SQL,
                    [
                        'tenant_id' => $tenantId,
                        'role_id' => $roleId,
                        'permission_code' => $permission,
                    ],
                );
            }
        }
    }
}
