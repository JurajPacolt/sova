<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce immutable audit events and add stable security-audit keyset indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION prevent_audit_event_mutation()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    '% is append-only', TG_TABLE_NAME
                    USING ERRCODE = '55000';
            END;
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_security_audit_events_append_only
            BEFORE UPDATE OR DELETE ON security_audit_events
            FOR EACH ROW
            EXECUTE FUNCTION prevent_audit_event_mutation()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_authentication_events_append_only
            BEFORE UPDATE OR DELETE ON authentication_events
            FOR EACH ROW
            EXECUTE FUNCTION prevent_audit_event_mutation()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_security_audit_events_tenant_page
            ON security_audit_events (tenant_id, occurred_at DESC, id DESC)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_security_audit_events_system_page
            ON security_audit_events (occurred_at DESC, id DESC)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX idx_security_audit_events_system_page',
        );
        $this->addSql(
            'DROP INDEX idx_security_audit_events_tenant_page',
        );
        $this->addSql(
            'DROP TRIGGER trg_security_audit_events_append_only'
                . ' ON security_audit_events',
        );
        $this->addSql(
            'DROP TRIGGER IF EXISTS trg_authentication_events_append_only'
                . ' ON authentication_events',
        );
        $this->addSql(
            'DROP FUNCTION IF EXISTS prevent_audit_event_mutation()',
        );
        $this->addSql(
            'DROP FUNCTION IF EXISTS prevent_security_audit_event_mutation()',
        );
    }
}
