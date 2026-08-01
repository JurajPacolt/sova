<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted TOTP credentials, recovery codes, and MFA session assurance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_mfa_credentials (
                user_id UUID NOT NULL,
                secret_key_id VARCHAR(64) NOT NULL,
                encrypted_secret TEXT NOT NULL,
                enabled_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                recovery_code_hashes JSONB NOT NULL DEFAULT '[]'::jsonb,
                last_used_step BIGINT DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_user_mfa_credentials PRIMARY KEY (user_id),
                CONSTRAINT fk_user_mfa_credentials_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT chk_user_mfa_credentials_key_id CHECK (
                    secret_key_id ~ '^[A-Za-z0-9._-]{1,64}$'
                ),
                CONSTRAINT chk_user_mfa_credentials_secret CHECK (
                    BTRIM(encrypted_secret) <> ''
                ),
                CONSTRAINT chk_user_mfa_credentials_recovery_codes CHECK (
                    jsonb_typeof(recovery_code_hashes) = 'array'
                ),
                CONSTRAINT chk_user_mfa_credentials_last_step CHECK (
                    last_used_step IS NULL OR last_used_step >= 0
                ),
                CONSTRAINT chk_user_mfa_credentials_timestamps CHECK (
                    updated_at >= created_at
                    AND (enabled_at IS NULL OR enabled_at >= created_at)
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE user_sessions
            ADD mfa_verified_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD CONSTRAINT chk_user_sessions_mfa_verified CHECK (
                mfa_verified_at IS NULL
                OR mfa_verified_at <= expires_at
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE user_sessions
            DROP CONSTRAINT chk_user_sessions_mfa_verified,
            DROP COLUMN mfa_verified_at
            SQL);
        $this->addSql('DROP TABLE user_mfa_credentials');
    }
}
