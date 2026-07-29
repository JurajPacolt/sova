<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-member notification channel preferences. An absent row '
            . 'means the documented default for that kind.';
    }

    public function up(Schema $schema): void
    {
        // Only the choices a member actually made are stored; everything else
        // falls back to the defaults in the domain. That keeps a new event kind
        // from needing a backfill, and keeps "never touched it" distinguishable
        // from "deliberately turned it off".
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_preferences (
                tenant_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                kind VARCHAR(32) NOT NULL,
                in_app BOOLEAN NOT NULL,
                email BOOLEAN NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_notification_preferences PRIMARY KEY (membership_id, kind),
                CONSTRAINT chk_notification_preferences_kind
                    CHECK (kind ~ '^[A-Z][A-Z0-9_]*$'),
                CONSTRAINT fk_notification_preferences_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_notification_preferences_tenant
                ON notification_preferences (tenant_id, membership_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notification_preferences');
    }
}
