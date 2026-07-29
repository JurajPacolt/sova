<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the SovaQL search rate-limit buckets and the fulltext and '
            . 'sorting indexes the issue query compiler depends on.';
    }

    public function up(Schema $schema): void
    {
        // Same shape as the authentication limiters: the key is an HMAC, so no
        // tenant or user identifier is stored in clear.
        $this->addSql(<<<'SQL'
            CREATE TABLE issue_query_rate_limits (
                bucket_key CHAR(64) NOT NULL,
                request_count INTEGER NOT NULL,
                window_started_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                CONSTRAINT pk_issue_query_rate_limits PRIMARY KEY (bucket_key),
                CONSTRAINT chk_issue_query_rate_limits_key
                    CHECK (bucket_key ~ '^[0-9a-f]{64}$'),
                CONSTRAINT chk_issue_query_rate_limits_requests
                    CHECK (request_count > 0),
                CONSTRAINT chk_issue_query_rate_limits_timestamps
                    CHECK (updated_at >= window_started_at)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_query_rate_limits_cleanup
                ON issue_query_rate_limits (updated_at)
            SQL);

        // Fulltext over the title and description, matching the expression the
        // compiler emits exactly — a different configuration or column order
        // would make the index unusable for the query.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_fulltext
                ON issues
                USING GIN (to_tsvector('simple', title || ' ' || description))
            SQL);

        // `ORDER BY title` — a B-tree on the lowered title serves the sort.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_title_lower
                ON issues (tenant_id, project_id, LOWER(title))
            SQL);

        // `title ~ "..."` is a contains search, so it starts with a wildcard and
        // the B-tree above cannot serve it — measured plans fell back to a
        // sequential scan. A trigram index makes the documented
        // "index-optimised case-insensitive title search" of the spec real
        // instead of relying on the statement timeout to bound a full scan.
        // The index is on `title` itself, not on `LOWER(title)`: pg_trgm handles
        // the case folding of `ILIKE` internally, and matching the compiler's
        // expression exactly is what keeps the index usable.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_title_trigram
                ON issues
                USING GIN (title gin_trgm_ops)
            SQL);

        // Sorting and filtering by the remaining timestamps and by priority.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_created
                ON issues (tenant_id, project_id, created_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_resolved
                ON issues (tenant_id, project_id, resolved_at DESC)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_priority
                ON issues (tenant_id, project_id, priority)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_project_type
                ON issues (tenant_id, project_id, issue_type_id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issues_workgroup
                ON issues (tenant_id, assignee_workgroup_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_issues_workgroup');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_project_type');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_project_priority');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_project_resolved');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_project_created');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_title_trigram');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_title_lower');
        $this->addSql('DROP INDEX IF EXISTS idx_issues_fulltext');
        $this->addSql('DROP TABLE IF EXISTS issue_query_rate_limits');
    }
}
