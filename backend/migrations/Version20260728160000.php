<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add issue watchers and issue links, both scoped by composite '
            . 'tenant foreign keys and confined to a single tenant.';
    }

    public function up(Schema $schema): void
    {
        // `watching` is deliberately a column rather than the presence of the
        // row. An explicit unwatch has to survive the automatic rules — being
        // assigned or commenting must not silently re-subscribe someone who
        // turned the issue off — so "not watching" is a stored decision, not an
        // absent row.
        $this->addSql(<<<'SQL'
            CREATE TABLE issue_watchers (
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                issue_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                watching BOOLEAN NOT NULL DEFAULT TRUE,
                source VARCHAR(16) NOT NULL DEFAULT 'EXPLICIT',
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_issue_watchers PRIMARY KEY (issue_id, membership_id),
                CONSTRAINT chk_issue_watchers_source CHECK (
                    source IN ('EXPLICIT', 'AUTHOR', 'ASSIGNEE', 'COMMENT')
                ),
                CONSTRAINT fk_issue_watchers_issue
                    FOREIGN KEY (tenant_id, project_id, issue_id)
                    REFERENCES issues (tenant_id, project_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_watchers_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE
            )
            SQL);

        // Reading "issues I watch" is as common as reading "who watches this".
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_watchers_membership
                ON issue_watchers (tenant_id, membership_id)
                WHERE watching
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_watchers_issue
                ON issue_watchers (tenant_id, issue_id)
                WHERE watching
            SQL);

        // A link references an issue by tenant alone, because the two sides may
        // legitimately live in different projects of the same tenant. The id is
        // already unique on its own; this composite unique exists only so the
        // tenant-scoped foreign key below is expressible.
        $this->addSql(<<<'SQL'
            ALTER TABLE issues
                ADD CONSTRAINT uniq_issues_tenant_id UNIQUE (tenant_id, id)
            SQL);

        // A link is stored once and read from both ends with the inverse label,
        // which is what keeps the two directions from ever disagreeing. Both
        // sides share one `tenant_id` column, so a cross-tenant link is not
        // representable rather than merely rejected.
        $this->addSql(<<<'SQL'
            CREATE TABLE issue_links (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                source_issue_id UUID NOT NULL,
                target_issue_id UUID NOT NULL,
                link_type VARCHAR(16) NOT NULL,
                created_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_issue_links PRIMARY KEY (id),
                CONSTRAINT uniq_issue_links_pair
                    UNIQUE (source_issue_id, target_issue_id, link_type),
                CONSTRAINT chk_issue_links_type CHECK (
                    link_type IN ('BLOCKS', 'RELATES_TO', 'DUPLICATES')
                ),
                CONSTRAINT chk_issue_links_not_self
                    CHECK (source_issue_id <> target_issue_id),
                CONSTRAINT fk_issue_links_source
                    FOREIGN KEY (tenant_id, source_issue_id)
                    REFERENCES issues (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_links_target
                    FOREIGN KEY (tenant_id, target_issue_id)
                    REFERENCES issues (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_links_creator
                    FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_links_source
                ON issue_links (tenant_id, source_issue_id)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_links_target
                ON issue_links (tenant_id, target_issue_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS issue_links');
        $this->addSql(
            'ALTER TABLE issues DROP CONSTRAINT IF EXISTS uniq_issues_tenant_id',
        );
        $this->addSql('DROP TABLE IF EXISTS issue_watchers');
    }
}
