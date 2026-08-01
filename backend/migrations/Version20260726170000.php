<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create hashed one-time user tokens and tenant invitations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_action_tokens (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_user_action_tokens PRIMARY KEY (id),
                CONSTRAINT uniq_user_action_tokens_hash UNIQUE (token_hash),
                CONSTRAINT fk_user_action_tokens_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_action_tokens_purpose CHECK (
                    purpose IN ('PASSWORD_RESET', 'EMAIL_VERIFICATION')
                ),
                CONSTRAINT chk_user_action_tokens_hash CHECK (
                    token_hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_user_action_tokens_expiry CHECK (
                    expires_at > created_at
                ),
                CONSTRAINT chk_user_action_tokens_used CHECK (
                    used_at IS NULL OR used_at >= created_at
                ),
                CONSTRAINT chk_user_action_tokens_revoked CHECK (
                    revoked_at IS NULL OR revoked_at >= created_at
                ),
                CONSTRAINT chk_user_action_tokens_terminal_state CHECK (
                    used_at IS NULL OR revoked_at IS NULL
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_user_action_tokens_active_purpose
            ON user_action_tokens (user_id, purpose)
            WHERE used_at IS NULL AND revoked_at IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_user_action_tokens_expiry
            ON user_action_tokens (expires_at)
            WHERE used_at IS NULL AND revoked_at IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_invitations (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                email VARCHAR(254) NOT NULL,
                normalized_email VARCHAR(254) NOT NULL,
                invited_by_user_id UUID NOT NULL,
                token_hash CHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                accepted_by_user_id UUID DEFAULT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                accepted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_tenant_invitations PRIMARY KEY (id),
                CONSTRAINT uniq_tenant_invitations_tenant_id_id UNIQUE (tenant_id, id),
                CONSTRAINT uniq_tenant_invitations_hash UNIQUE (token_hash),
                CONSTRAINT fk_tenant_invitations_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_tenant_invitations_invited_by FOREIGN KEY (invited_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_tenant_invitations_accepted_by FOREIGN KEY (accepted_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_tenant_invitations_email CHECK (
                    normalized_email = LOWER(BTRIM(normalized_email))
                    AND normalized_email = LOWER(BTRIM(email))
                    AND normalized_email <> ''
                ),
                CONSTRAINT chk_tenant_invitations_hash CHECK (
                    token_hash ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_tenant_invitations_status CHECK (
                    status IN ('PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED')
                ),
                CONSTRAINT chk_tenant_invitations_expiry CHECK (
                    expires_at > created_at
                ),
                CONSTRAINT chk_tenant_invitations_timestamps CHECK (
                    updated_at >= created_at
                ),
                CONSTRAINT chk_tenant_invitations_terminal_state CHECK (
                    (
                        status IN ('PENDING', 'EXPIRED')
                        AND accepted_by_user_id IS NULL
                        AND accepted_at IS NULL
                        AND revoked_at IS NULL
                    )
                    OR (
                        status = 'ACCEPTED'
                        AND accepted_by_user_id IS NOT NULL
                        AND accepted_at IS NOT NULL
                        AND accepted_at >= created_at
                        AND revoked_at IS NULL
                    )
                    OR (
                        status = 'REVOKED'
                        AND accepted_by_user_id IS NULL
                        AND accepted_at IS NULL
                        AND revoked_at IS NOT NULL
                        AND revoked_at >= created_at
                    )
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_tenant_invitations_pending_email
            ON tenant_invitations (tenant_id, normalized_email)
            WHERE status = 'PENDING'
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_tenant_invitations_tenant_status
            ON tenant_invitations (tenant_id, status, created_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_tenant_invitations_expiry
            ON tenant_invitations (expires_at)
            WHERE status = 'PENDING'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_invitations');
        $this->addSql('DROP TABLE user_action_tokens');
    }
}
