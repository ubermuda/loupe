<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929205001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create bridge_card_holds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_card_holds (id UUID NOT NULL, card_id UUID NOT NULL, held_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, stopped_run_id UUID DEFAULT NULL, held_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E1311559166D1F9C ON bridge_card_holds (project_id)');
        $this->addSql('CREATE INDEX IDX_E1311559CDBC71CA ON bridge_card_holds (stopped_run_id)');
        $this->addSql('CREATE INDEX IDX_E13115595EEE5820 ON bridge_card_holds (held_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_card_hold ON bridge_card_holds (project_id, card_id)');
        $this->addSql('ALTER TABLE bridge_card_holds ADD CONSTRAINT FK_E1311559166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_card_holds ADD CONSTRAINT FK_E1311559CDBC71CA FOREIGN KEY (stopped_run_id) REFERENCES bridge_worker_runs (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_card_holds ADD CONSTRAINT FK_E13115595EEE5820 FOREIGN KEY (held_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_card_holds DROP CONSTRAINT FK_E1311559166D1F9C');
        $this->addSql('ALTER TABLE bridge_card_holds DROP CONSTRAINT FK_E1311559CDBC71CA');
        $this->addSql('ALTER TABLE bridge_card_holds DROP CONSTRAINT FK_E13115595EEE5820');
        $this->addSql('DROP TABLE bridge_card_holds');
    }
}
