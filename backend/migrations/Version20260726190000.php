<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification authentication audit event types.';
    }

    public function up(Schema $schema): void
    {
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
                    'PASSWORD_RESET_COMPLETED',
                    'EMAIL_VERIFICATION_REQUESTED',
                    'EMAIL_VERIFIED'
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
}
