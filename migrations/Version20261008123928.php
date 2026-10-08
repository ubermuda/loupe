<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008123928 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the widget verdict, verdict delivery and site review check tables, and add the two widget opt-ins to the board settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_verdict_deliveries (id UUID NOT NULL, state VARCHAR(20) NOT NULL, reason VARCHAR(50) DEFAULT NULL, review_url VARCHAR(512) DEFAULT NULL, settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, verdict_id UUID NOT NULL, pull_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6449DF371391DFBF ON board_card_verdict_deliveries (verdict_id)');
        $this->addSql('CREATE INDEX IDX_6449DF374CE0BF7E ON board_card_verdict_deliveries (pull_request_id)');
        $this->addSql('CREATE INDEX idx_board_card_verdict_deliveries_state ON board_card_verdict_deliveries (state)');
        $this->addSql('CREATE TABLE board_card_verdicts (id UUID NOT NULL, kind VARCHAR(20) NOT NULL, message TEXT NOT NULL, notes JSONB DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, reviewer_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5B2ECB84ACC9A20 ON board_card_verdicts (card_id)');
        $this->addSql('CREATE INDEX IDX_5B2ECB870574616 ON board_card_verdicts (reviewer_id)');
        $this->addSql('CREATE INDEX idx_board_card_verdicts_card_created ON board_card_verdicts (card_id, created_at)');
        $this->addSql('CREATE TABLE board_site_review_check_states (id UUID NOT NULL, head_sha VARCHAR(64) NOT NULL, conclusion VARCHAR(20) NOT NULL, note_count INT NOT NULL, check_run_id BIGINT DEFAULT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pull_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8EDF5D974CE0BF7E ON board_site_review_check_states (pull_request_id)');
        $this->addSql('ALTER TABLE board_card_verdict_deliveries ADD CONSTRAINT FK_6449DF371391DFBF FOREIGN KEY (verdict_id) REFERENCES board_card_verdicts (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_verdict_deliveries ADD CONSTRAINT FK_6449DF374CE0BF7E FOREIGN KEY (pull_request_id) REFERENCES forge_pull_requests (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_verdicts ADD CONSTRAINT FK_5B2ECB84ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_verdicts ADD CONSTRAINT FK_5B2ECB870574616 FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_site_review_check_states ADD CONSTRAINT FK_8EDF5D974CE0BF7E FOREIGN KEY (pull_request_id) REFERENCES forge_pull_requests (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_automation_settings ADD post_widget_reviews BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD site_review_check BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_verdict_deliveries DROP CONSTRAINT FK_6449DF371391DFBF');
        $this->addSql('ALTER TABLE board_card_verdict_deliveries DROP CONSTRAINT FK_6449DF374CE0BF7E');
        $this->addSql('ALTER TABLE board_card_verdicts DROP CONSTRAINT FK_5B2ECB84ACC9A20');
        $this->addSql('ALTER TABLE board_card_verdicts DROP CONSTRAINT FK_5B2ECB870574616');
        $this->addSql('ALTER TABLE board_site_review_check_states DROP CONSTRAINT FK_8EDF5D974CE0BF7E');
        $this->addSql('DROP TABLE board_card_verdict_deliveries');
        $this->addSql('DROP TABLE board_card_verdicts');
        $this->addSql('DROP TABLE board_site_review_check_states');
        $this->addSql('ALTER TABLE board_automation_settings DROP post_widget_reviews');
        $this->addSql('ALTER TABLE board_automation_settings DROP site_review_check');
    }
}
