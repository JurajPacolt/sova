<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant localization settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
            ADD default_locale VARCHAR(8) NOT NULL DEFAULT 'sk',
            ADD timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Bratislava',
            ADD CONSTRAINT chk_tenants_default_locale CHECK (
                default_locale IN ('sk', 'en', 'cs', 'de', 'hu', 'pl')
            ),
            ADD CONSTRAINT chk_tenants_timezone CHECK (BTRIM(timezone) <> '')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants
            DROP CONSTRAINT chk_tenants_timezone,
            DROP CONSTRAINT chk_tenants_default_locale,
            DROP COLUMN timezone,
            DROP COLUMN default_locale
            SQL);
    }
}
