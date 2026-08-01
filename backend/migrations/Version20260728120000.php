<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add issue resolution and resolved_at columns so workflow '
            . 'transition rules can set or clear a resolution at runtime.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE issues
                ADD COLUMN resolution VARCHAR(64) DEFAULT NULL,
                ADD COLUMN resolved_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                ADD CONSTRAINT chk_issues_resolution
                    CHECK (resolution IS NULL OR BTRIM(resolution) <> '')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE issues
                DROP CONSTRAINT chk_issues_resolution,
                DROP COLUMN resolved_at,
                DROP COLUMN resolution
            SQL);
    }
}
