<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009034500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the foreign keys of the workflow tables to board cards and board columns, which keep plain ids';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the workflow tables keep plain ids, so no code reads the foreign key, and a rollback loses only the cascade, which a deleted card no longer needs.
        $this->addSql('ALTER TABLE workflow_pending_baselines DROP CONSTRAINT fk_111053f04acc9a20');
        // @contract-phase: the workflow tables keep plain ids, so no code reads the foreign key, and a rollback loses only the cascade, which a deleted card no longer needs.
        $this->addSql('ALTER TABLE workflow_rule_states DROP CONSTRAINT fk_fc466c204acc9a20');
        $this->addSql('DROP INDEX idx_fc466c204acc9a20');
        // @contract-phase: the workflow tables keep plain ids, so no code reads the foreign key, and a rollback loses only the cascade, which a deleted card no longer needs.
        $this->addSql('ALTER TABLE workflow_slot_links DROP CONSTRAINT fk_22185060be8e8ed5');
        $this->addSql('DROP INDEX idx_22185060be8e8ed5');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_pending_baselines ADD CONSTRAINT fk_111053f04acc9a20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE workflow_rule_states ADD CONSTRAINT fk_fc466c204acc9a20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_fc466c204acc9a20 ON workflow_rule_states (card_id)');
        $this->addSql('ALTER TABLE workflow_slot_links ADD CONSTRAINT fk_22185060be8e8ed5 FOREIGN KEY (column_id) REFERENCES board_columns (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_22185060be8e8ed5 ON workflow_slot_links (column_id)');
    }
}
