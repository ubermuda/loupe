<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009031500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the type and a reason code on each inbox card wait, instead of an English sentence';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inbox_card_waits ADD type VARCHAR(20) NOT NULL DEFAULT 'document'");
        $this->addSql("UPDATE inbox_card_waits SET type = CASE trigger
            WHEN 'document-in-review' THEN 'document'
            WHEN 'pull-request-ready' THEN 'pull-request'
            WHEN 'pull-request-fix-stopped' THEN 'pull-request'
            WHEN 'card-paused' THEN 'card-pause'
            ELSE 'worker-run' END");
        $this->addSql("UPDATE inbox_card_waits SET reason = CASE
            WHEN trigger = 'run-blocked' THEN 'blocked'
            WHEN trigger = 'run-gave-up' THEN 'gave-up'
            WHEN trigger = 'run-waiting-for-person' THEN 'waits-for-person'
            WHEN trigger = 'pull-request-fix-stopped' THEN 'fix-stopped'
            WHEN trigger = 'pull-request-ready' AND reason LIKE '%after your approval%' THEN 'new-commits-after-approval'
            WHEN trigger = 'card-paused' THEN COALESCE((SELECT kind FROM card_pauses WHERE card_pauses.id = inbox_card_waits.pause_id), 'work-stopped')
            ELSE 'waiting-for-review' END");
        $this->addSql('ALTER TABLE inbox_card_waits ALTER type DROP DEFAULT');
    }

    /** The English sentences are not kept, so a rollback leaves the codes in the old column. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_card_waits DROP type');
    }
}
