<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The existing unique constraint on (tenant_id, project_id, id) already owns a
 * B-tree with exactly the search-scope shape. Keep the original migration
 * immutable, then remove its redundant index explicitly.
 */
final class Version20260729510000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the redundant issue search scope index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_issues_search_scope');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_search_scope
                ON issues (tenant_id, project_id, id)
            SQL);
    }
}
