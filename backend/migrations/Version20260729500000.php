<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avoid recomputing the full-text vector for every candidate row.
 *
 * PostgreSQL deliberately will not push non-leakproof full-text and trigram
 * operators ahead of a Row-Level Security barrier. The GIN index remains
 * useful to roles that do not cross that barrier, while the stored vector and
 * tenant/project scope index keep enforced-RLS evaluation bounded and cheap
 * without weakening isolation.
 */
final class Version20260729500000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the issue search vector and add an RLS-safe project scope index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE issues
            ADD COLUMN search_vector TSVECTOR
                GENERATED ALWAYS AS (
                    to_tsvector('simple', title || ' ' || description)
                ) STORED
            SQL);
        $this->addSql('DROP INDEX idx_issues_fulltext');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_fulltext
                ON issues
                USING GIN (search_vector)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_search_scope
                ON issues (tenant_id, project_id, id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_issues_search_scope');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_fulltext');
        $this->addSql('ALTER TABLE issues DROP COLUMN search_vector');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_fulltext
                ON issues
                USING GIN (
                    to_tsvector('simple', title || ' ' || description)
                )
            SQL);
    }
}
