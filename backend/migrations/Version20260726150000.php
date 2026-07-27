<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create login rate-limit buckets and authentication audit events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE authentication_rate_limits (
                bucket_key CHAR(64) NOT NULL,
                bucket_type VARCHAR(16) NOT NULL,
                attempt_count INTEGER NOT NULL,
                window_started_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                blocked_until TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                CONSTRAINT pk_authentication_rate_limits PRIMARY KEY (bucket_key),
                CONSTRAINT chk_authentication_rate_limits_key CHECK (
                    bucket_key ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_authentication_rate_limits_type CHECK (
                    bucket_type IN ('ACCOUNT', 'IP')
                ),
                CONSTRAINT chk_authentication_rate_limits_attempts CHECK (
                    attempt_count > 0
                ),
                CONSTRAINT chk_authentication_rate_limits_timestamps CHECK (
                    updated_at >= window_started_at
                    AND (
                        blocked_until IS NULL
                        OR blocked_until >= window_started_at
                    )
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_authentication_rate_limits_cleanup
            ON authentication_rate_limits (updated_at)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE authentication_events (
                id UUID NOT NULL,
                user_id UUID DEFAULT NULL,
                session_id UUID DEFAULT NULL,
                event_type VARCHAR(32) NOT NULL,
                outcome VARCHAR(16) NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                request_id VARCHAR(128) NOT NULL,
                ip_address INET DEFAULT NULL,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_authentication_events PRIMARY KEY (id),
                CONSTRAINT fk_authentication_events_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT chk_authentication_events_type CHECK (
                    event_type IN ('LOGIN', 'LOGOUT', 'SESSION_REVOKED')
                ),
                CONSTRAINT chk_authentication_events_outcome CHECK (
                    outcome IN ('SUCCESS', 'FAILURE', 'RATE_LIMITED')
                ),
                CONSTRAINT chk_authentication_events_reason CHECK (
                    reason_code ~ '^[A-Z][A-Z0-9_]*$'
                ),
                CONSTRAINT chk_authentication_events_request_id CHECK (
                    request_id ~ '^[A-Za-z0-9._-]{8,128}$'
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_authentication_events_user_occurred
            ON authentication_events (user_id, occurred_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_authentication_events_occurred
            ON authentication_events (occurred_at DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE authentication_events');
        $this->addSql('DROP TABLE authentication_rate_limits');
    }
}
