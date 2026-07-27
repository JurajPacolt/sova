<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create recovery throttling and the transactional outbox foundation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE authentication_recovery_rate_limits (
                bucket_key CHAR(64) NOT NULL,
                bucket_type VARCHAR(16) NOT NULL,
                request_count INTEGER NOT NULL,
                window_started_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                blocked_until TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                CONSTRAINT pk_authentication_recovery_rate_limits PRIMARY KEY (bucket_key),
                CONSTRAINT chk_authentication_recovery_rate_limits_key CHECK (
                    bucket_key ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT chk_authentication_recovery_rate_limits_type CHECK (
                    bucket_type IN ('ACCOUNT', 'IP')
                ),
                CONSTRAINT chk_authentication_recovery_rate_limits_requests CHECK (
                    request_count > 0
                ),
                CONSTRAINT chk_authentication_recovery_rate_limits_timestamps CHECK (
                    updated_at >= window_started_at
                    AND (
                        blocked_until IS NULL
                        OR blocked_until >= window_started_at
                    )
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_authentication_recovery_rate_limits_cleanup
            ON authentication_recovery_rate_limits (updated_at)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE outbox_events (
                id UUID NOT NULL,
                tenant_id UUID DEFAULT NULL,
                aggregate_type VARCHAR(64) NOT NULL,
                aggregate_id UUID NOT NULL,
                event_name VARCHAR(96) NOT NULL,
                event_version SMALLINT NOT NULL,
                sequence_number BIGINT NOT NULL,
                payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                available_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                failed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                last_error VARCHAR(512) DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_outbox_events PRIMARY KEY (id),
                CONSTRAINT fk_outbox_events_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE RESTRICT,
                CONSTRAINT uniq_outbox_events_aggregate_sequence UNIQUE (
                    aggregate_type,
                    aggregate_id,
                    sequence_number
                ),
                CONSTRAINT chk_outbox_events_aggregate_type CHECK (
                    aggregate_type ~ '^[A-Z][A-Z0-9_]*$'
                ),
                CONSTRAINT chk_outbox_events_name CHECK (
                    event_name ~ '^[A-Z][A-Z0-9_]*$'
                ),
                CONSTRAINT chk_outbox_events_version CHECK (
                    event_version > 0
                ),
                CONSTRAINT chk_outbox_events_sequence CHECK (
                    sequence_number > 0
                ),
                CONSTRAINT chk_outbox_events_payload CHECK (
                    jsonb_typeof(payload) = 'object'
                ),
                CONSTRAINT chk_outbox_events_attempts CHECK (
                    attempt_count >= 0
                ),
                CONSTRAINT chk_outbox_events_terminal_state CHECK (
                    processed_at IS NULL OR failed_at IS NULL
                ),
                CONSTRAINT chk_outbox_events_processed CHECK (
                    processed_at IS NULL OR processed_at >= created_at
                ),
                CONSTRAINT chk_outbox_events_failed CHECK (
                    failed_at IS NULL OR failed_at >= created_at
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_events_available
            ON outbox_events (available_at, created_at, id)
            WHERE processed_at IS NULL AND failed_at IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_events_tenant_created
            ON outbox_events (tenant_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE outbox_sensitive_payloads (
                event_id UUID NOT NULL,
                key_id VARCHAR(64) NOT NULL,
                ciphertext TEXT NOT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                consumed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_outbox_sensitive_payloads PRIMARY KEY (event_id),
                CONSTRAINT fk_outbox_sensitive_payloads_event FOREIGN KEY (event_id)
                    REFERENCES outbox_events (id) ON DELETE CASCADE,
                CONSTRAINT chk_outbox_sensitive_payloads_key_id CHECK (
                    key_id ~ '^[A-Za-z0-9._-]{1,64}$'
                ),
                CONSTRAINT chk_outbox_sensitive_payloads_ciphertext CHECK (
                    BTRIM(ciphertext) <> ''
                ),
                CONSTRAINT chk_outbox_sensitive_payloads_expiry CHECK (
                    expires_at > created_at
                ),
                CONSTRAINT chk_outbox_sensitive_payloads_consumed CHECK (
                    consumed_at IS NULL OR consumed_at >= created_at
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_sensitive_payloads_expiry
            ON outbox_sensitive_payloads (expires_at)
            WHERE consumed_at IS NULL
            SQL);

        $this->addSql(
            'ALTER TABLE authentication_events '
            . 'DROP CONSTRAINT chk_authentication_events_type',
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE authentication_events
            ADD CONSTRAINT chk_authentication_events_type CHECK (
                event_type IN (
                    'LOGIN',
                    'LOGOUT',
                    'SESSION_REVOKED',
                    'PASSWORD_RESET_REQUESTED',
                    'PASSWORD_RESET_COMPLETED'
                )
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE authentication_events '
            . 'DROP CONSTRAINT chk_authentication_events_type',
        );
        $this->addSql(<<<'SQL'
            ALTER TABLE authentication_events
            ADD CONSTRAINT chk_authentication_events_type CHECK (
                event_type IN ('LOGIN', 'LOGOUT', 'SESSION_REVOKED')
            )
            SQL);
        $this->addSql('DROP TABLE outbox_sensitive_payloads');
        $this->addSql('DROP TABLE outbox_events');
        $this->addSql('DROP TABLE authentication_recovery_rate_limits');
    }
}
