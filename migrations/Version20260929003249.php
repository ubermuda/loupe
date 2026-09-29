<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929003249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create decision answers, with one row for each answered decision';
    }

    /**
     * The generated diff also carried the pending contract steps of earlier
     * releases on api_tokens, board_cards.priority and the project token
     * columns. They wait for their own release under docs/operating/migrations.md.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE decision_answers (id UUID NOT NULL, decision_id VARCHAR(64) NOT NULL, note TEXT DEFAULT NULL, answered_at_version INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id UUID NOT NULL, answered_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_84120D19C33F7837 ON decision_answers (document_id)');
        $this->addSql('CREATE INDEX IDX_84120D192FC55A77 ON decision_answers (answered_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_decision_answer ON decision_answers (document_id, decision_id)');
        $this->addSql('ALTER TABLE decision_answers ADD CONSTRAINT FK_84120D19C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE decision_answers ADD CONSTRAINT FK_84120D192FC55A77 FOREIGN KEY (answered_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision_answers DROP CONSTRAINT FK_84120D19C33F7837');
        $this->addSql('ALTER TABLE decision_answers DROP CONSTRAINT FK_84120D192FC55A77');
        $this->addSql('DROP TABLE decision_answers');
    }
}
