<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add in-app notifications, keyed by the outbox event that '
            . 'produced them so at-least-once delivery cannot duplicate them.';
    }

    public function up(Schema $schema): void
    {
        // `uniq_notifications_delivery` is the whole idempotency story. Outbox
        // delivery is at-least-once, so the same event will occasionally be
        // handled twice; keying the row on (event, recipient, kind) turns the
        // second attempt into a no-op instead of a duplicate in someone's inbox.
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                recipient_membership_id UUID NOT NULL,
                event_id UUID NOT NULL,
                kind VARCHAR(32) NOT NULL,
                project_id UUID DEFAULT NULL,
                issue_id UUID DEFAULT NULL,
                actor_user_id UUID DEFAULT NULL,
                payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                read_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_notifications PRIMARY KEY (id),
                CONSTRAINT uniq_notifications_delivery
                    UNIQUE (event_id, recipient_membership_id, kind),
                CONSTRAINT chk_notifications_kind
                    CHECK (kind ~ '^[A-Z][A-Z0-9_]*$'),
                CONSTRAINT chk_notifications_payload
                    CHECK (jsonb_typeof(payload) = 'object'),
                CONSTRAINT fk_notifications_recipient
                    FOREIGN KEY (tenant_id, recipient_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_notifications_event
                    FOREIGN KEY (event_id)
                    REFERENCES outbox_events (id) ON DELETE CASCADE,
                CONSTRAINT fk_notifications_issue
                    FOREIGN KEY (tenant_id, issue_id)
                    REFERENCES issues (tenant_id, id) ON DELETE CASCADE
            )
            SQL);

        // The inbox is read newest first, and the unread badge is the same
        // query with a narrower predicate.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_notifications_recipient
                ON notifications (
                    tenant_id, recipient_membership_id, created_at DESC, id DESC
                )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_notifications_unread
                ON notifications (tenant_id, recipient_membership_id)
                WHERE read_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notifications');
    }
}
