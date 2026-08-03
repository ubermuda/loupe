<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803132824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create decision_selections, a reviewer\'s answer per document and decision id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE decision_selections (id UUID NOT NULL, decision_id VARCHAR(64) NOT NULL, option_index INT NOT NULL, option_label TEXT NOT NULL, version_number INT NOT NULL, selected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_493164F0C33F7837 ON decision_selections (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_decision_selection_id ON decision_selections (document_id, decision_id)');
        $this->addSql('ALTER TABLE decision_selections ADD CONSTRAINT FK_493164F0C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision_selections DROP CONSTRAINT FK_493164F0C33F7837');
        $this->addSql('DROP TABLE decision_selections');
    }
}
