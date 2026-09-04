<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904161417 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Key a decision selection by its option index, so a block can take several answers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_decision_selection_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_decision_selection_option ON decision_selections (document_id, decision_id, option_index)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_decision_selection_option');
        $this->addSql('CREATE UNIQUE INDEX uniq_decision_selection_id ON decision_selections (document_id, decision_id)');
    }
}
