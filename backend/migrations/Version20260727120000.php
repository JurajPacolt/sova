<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add session-bound, tenant-scoped, time-limited impersonations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE impersonations (
                id UUID NOT NULL,
                session_id UUID NOT NULL,
                actor_user_id UUID NOT NULL,
                effective_user_id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                reason VARCHAR(500) NOT NULL,
                reauthenticated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                started_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                ended_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                end_reason VARCHAR(64) DEFAULT NULL,
                CONSTRAINT pk_impersonations PRIMARY KEY (id),
                CONSTRAINT fk_impersonations_session FOREIGN KEY (session_id)
                    REFERENCES user_sessions (id) ON DELETE CASCADE,
                CONSTRAINT fk_impersonations_actor FOREIGN KEY (actor_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_impersonations_effective_user FOREIGN KEY (effective_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_impersonations_tenant FOREIGN KEY (tenant_id)
                    REFERENCES tenants (id) ON DELETE RESTRICT,
                CONSTRAINT chk_impersonations_distinct_users CHECK (
                    actor_user_id <> effective_user_id
                ),
                CONSTRAINT chk_impersonations_reason CHECK (
                    CHAR_LENGTH(BTRIM(reason)) BETWEEN 10 AND 500
                ),
                CONSTRAINT chk_impersonations_expiry CHECK (
                    expires_at > started_at
                    AND expires_at <= started_at + INTERVAL '15 minutes'
                ),
                CONSTRAINT chk_impersonations_reauthentication CHECK (
                    reauthenticated_at <= started_at
                    AND reauthenticated_at >= started_at - INTERVAL '5 minutes'
                ),
                CONSTRAINT chk_impersonations_end CHECK (
                    (ended_at IS NULL AND end_reason IS NULL)
                    OR (
                        ended_at IS NOT NULL
                        AND ended_at >= started_at
                        AND end_reason IS NOT NULL
                        AND BTRIM(end_reason) <> ''
                    )
                )
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_impersonations_open_session
            ON impersonations (session_id)
            WHERE ended_at IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_impersonations_actor_started
            ON impersonations (actor_user_id, started_at DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_impersonations_tenant_started
            ON impersonations (tenant_id, started_at DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE impersonations');
    }
}
