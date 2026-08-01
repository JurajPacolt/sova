<?php

declare(strict_types=1);

namespace Sova\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add issue comments and their structured mentions, both scoped by '
            . 'composite tenant and project foreign keys.';
    }

    public function up(Schema $schema): void
    {
        // The body is the original CommonMark source; nothing here ever stores
        // rendered HTML. Deletion is soft so the activity stream can keep the
        // neutral "comment removed" placeholder without keeping the text.
        $this->addSql(<<<'SQL'
            CREATE TABLE issue_comments (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                project_id UUID NOT NULL,
                issue_id UUID NOT NULL,
                author_membership_id UUID NOT NULL,
                body TEXT NOT NULL,
                version BIGINT NOT NULL DEFAULT 1,
                edited_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                deleted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                deleted_by_user_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_issue_comments PRIMARY KEY (id),
                CONSTRAINT uniq_issue_comments_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT chk_issue_comments_version CHECK (version >= 1),
                CONSTRAINT chk_issue_comments_body
                    CHECK (deleted_at IS NOT NULL OR btrim(body) <> ''),
                CONSTRAINT chk_issue_comments_deleted
                    CHECK (
                        (deleted_at IS NULL AND deleted_by_user_id IS NULL)
                        OR (deleted_at IS NOT NULL AND deleted_by_user_id IS NOT NULL)
                    ),
                CONSTRAINT fk_issue_comments_issue
                    FOREIGN KEY (tenant_id, project_id, issue_id)
                    REFERENCES issues (tenant_id, project_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_comments_author
                    FOREIGN KEY (tenant_id, author_membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT fk_issue_comments_deleted_by
                    FOREIGN KEY (deleted_by_user_id)
                    REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_comments_issue
                ON issue_comments (tenant_id, issue_id, created_at, id)
            SQL);

        // Mentions are stored as resolved references, not as parsed text, so a
        // later rename of a member cannot silently change who was addressed.
        // The composite foreign keys keep both sides inside one tenant.
        $this->addSql(<<<'SQL'
            CREATE TABLE issue_comment_mentions (
                tenant_id UUID NOT NULL,
                comment_id UUID NOT NULL,
                membership_id UUID NOT NULL,
                CONSTRAINT pk_issue_comment_mentions
                    PRIMARY KEY (comment_id, membership_id),
                CONSTRAINT fk_issue_comment_mentions_comment
                    FOREIGN KEY (tenant_id, comment_id)
                    REFERENCES issue_comments (tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_comment_mentions_membership
                    FOREIGN KEY (tenant_id, membership_id)
                    REFERENCES tenant_memberships (tenant_id, id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_comment_mentions_membership
                ON issue_comment_mentions (tenant_id, membership_id)
            SQL);

        // The user-facing activity log is read per issue, newest first.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_issue_history_issue_created
                ON issue_history (tenant_id, issue_id, created_at DESC, id DESC)
            SQL);

        // `issue_history` was created when every entry accompanied a change to
        // the issue itself, so one row per issue version was a true invariant
        // and `UNIQUE (issue_id, issue_version)` guarded against recording a
        // transition twice. Comments break that assumption legitimately: the
        // specification requires them in the history (§6.7), and a comment must
        // not bump `issues.version` — doing so would invalidate every editor's
        // in-flight optimistic lock for something that did not touch the issue.
        //
        // Rather than dropping the guard, the exception is made explicit: rows
        // that accompany a change keep the uniqueness, rows that only annotate
        // the issue do not.
        $this->addSql(<<<'SQL'
            ALTER TABLE issue_history
                ADD COLUMN changes_issue BOOLEAN NOT NULL DEFAULT TRUE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE issue_history
                DROP CONSTRAINT uniq_issue_history_issue_version
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_issue_history_issue_version
                ON issue_history (issue_id, issue_version)
                WHERE changes_issue
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_issue_history_issue_version');
        // Annotating rows have to go before the stricter constraint can hold
        // again; they only exist because this migration allowed them.
        $this->addSql('DELETE FROM issue_history WHERE NOT changes_issue');
        $this->addSql('ALTER TABLE issue_history DROP COLUMN changes_issue');
        $this->addSql(<<<'SQL'
            ALTER TABLE issue_history
                ADD CONSTRAINT uniq_issue_history_issue_version
                UNIQUE (issue_id, issue_version)
            SQL);
        $this->addSql('DROP INDEX IF EXISTS idx_issue_history_issue_created');
        $this->addSql('DROP TABLE IF EXISTS issue_comment_mentions');
        $this->addSql('DROP TABLE IF EXISTS issue_comments');
    }
}
