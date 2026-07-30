<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729400000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add measured issue search indexes for reporter filtering and semantic priority order.';
    }

    public function up(Schema $schema): void
    {
        // Every issue query is already narrowed by tenant and project. Keeping
        // both columns first lets reporter filtering stay inside that boundary
        // and the final id serves the stable keyset tie-breaker without a sort.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_reporter
                ON issues (tenant_id, project_id, reporter_membership_id, id)
            SQL);

        // Priority is not ordered alphabetically in SovaQL. The old plain
        // priority index helped equality filters but could never serve the CASE
        // expression used by `ORDER BY priority DESC`; this expression is kept
        // byte-for-byte aligned with IssueQueryCompiler.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_priority_rank
                ON issues (
                    tenant_id,
                    project_id,
                    (
                        CASE priority
                            WHEN 'LOW' THEN 1
                            WHEN 'NORMAL' THEN 2
                            WHEN 'HIGH' THEN 3
                            WHEN 'CRITICAL' THEN 4
                            ELSE 0
                        END
                    ) DESC,
                    id ASC
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_issues_project_priority_rank');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_project_reporter');
    }
}
