<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the identity, tenancy, membership, and server session foundation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID NOT NULL,
                email VARCHAR(254) NOT NULL,
                normalized_email VARCHAR(254) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(160) NOT NULL,
                preferred_locale VARCHAR(5) NOT NULL DEFAULT 'sk',
                status VARCHAR(32) NOT NULL,
                email_verified_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                failed_login_count INTEGER NOT NULL DEFAULT 0,
                locked_until TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_users PRIMARY KEY (id),
                CONSTRAINT uniq_users_normalized_email UNIQUE (normalized_email),
                CONSTRAINT chk_users_normalized_email CHECK (
                    normalized_email = LOWER(BTRIM(normalized_email))
                    AND normalized_email = LOWER(BTRIM(email))
                    AND normalized_email <> ''
                ),
                CONSTRAINT chk_users_display_name CHECK (BTRIM(display_name) <> ''),
                CONSTRAINT chk_users_preferred_locale CHECK (
                    preferred_locale IN ('sk', 'cs', 'en', 'de', 'pl', 'hu')
                ),
                CONSTRAINT chk_users_status CHECK (
                    status IN (
                        'PENDING_VERIFICATION',
                        'ACTIVE',
                        'LOCKED',
                        'DISABLED',
                        'EXPIRED',
                        'DELETED'
                    )
                ),
                CONSTRAINT chk_users_failed_login_count CHECK (failed_login_count >= 0),
                CONSTRAINT chk_users_timestamps CHECK (updated_at >= created_at)
            )
            SQL);
        $this->addSql(
            'CREATE INDEX idx_users_status ON users (status)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id UUID NOT NULL,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(63) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenants PRIMARY KEY (id),
                CONSTRAINT uniq_tenants_slug UNIQUE (slug),
                CONSTRAINT chk_tenants_name CHECK (BTRIM(name) <> ''),
                CONSTRAINT chk_tenants_slug CHECK (
                    slug = LOWER(slug)
                    AND slug ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$'
                ),
                CONSTRAINT chk_tenants_status CHECK (
                    status IN (
                        'PENDING',
                        'ACTIVE',
                        'SUSPENDED',
                        'ARCHIVED',
                        'DELETION_PENDING',
                        'DELETED'
                    )
                ),
                CONSTRAINT chk_tenants_timestamps CHECK (updated_at >= created_at)
            )
            SQL);
        $this->addSql(
            'CREATE INDEX idx_tenants_status ON tenants (status)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_memberships (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                user_id UUID NOT NULL,
                status VARCHAR(32) NOT NULL,
                joined_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenant_memberships PRIMARY KEY (id),
                CONSTRAINT uniq_tenant_memberships_tenant_id_id UNIQUE (tenant_id, id),
                CONSTRAINT uniq_tenant_memberships_tenant_user UNIQUE (tenant_id, user_id),
                CONSTRAINT fk_tenant_memberships_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_tenant_memberships_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_tenant_memberships_status CHECK (
                    status IN ('ACTIVE', 'DISABLED', 'REMOVED')
                ),
                CONSTRAINT chk_tenant_memberships_timestamps CHECK (
                    updated_at >= created_at AND joined_at >= created_at
                )
            )
            SQL);
        $this->addSql(
            'CREATE INDEX idx_tenant_memberships_user_status '
            . 'ON tenant_memberships (user_id, status)',
        );
        $this->addSql(
            'CREATE INDEX idx_tenant_memberships_tenant_status '
            . 'ON tenant_memberships (tenant_id, status)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_sessions (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                token_hash CHAR(64) NOT NULL,
                csrf_token_hash CHAR(64) NOT NULL,
                ip_address INET DEFAULT NULL,
                user_agent VARCHAR(512) DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                revoked_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                revocation_reason VARCHAR(64) DEFAULT NULL,
                CONSTRAINT pk_user_sessions PRIMARY KEY (id),
                CONSTRAINT uniq_user_sessions_token_hash UNIQUE (token_hash),
                CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_sessions_token_hash CHECK (
                    token_hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_user_sessions_csrf_token_hash CHECK (
                    csrf_token_hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_user_sessions_expiry CHECK (expires_at > created_at),
                CONSTRAINT chk_user_sessions_last_seen CHECK (last_seen_at >= created_at),
                CONSTRAINT chk_user_sessions_revocation CHECK (
                    (revoked_at IS NULL AND revocation_reason IS NULL)
                    OR (
                        revoked_at IS NOT NULL
                        AND revoked_at >= created_at
                        AND revocation_reason IS NOT NULL
                        AND BTRIM(revocation_reason) <> ''
                    )
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_user_sessions_active_user
            ON user_sessions (user_id, expires_at)
            WHERE revoked_at IS NULL
            SQL);
        $this->addSql(
            'CREATE INDEX idx_user_sessions_expiry ON user_sessions (expires_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_sessions');
        $this->addSql('DROP TABLE tenant_memberships');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE users');
    }
}
