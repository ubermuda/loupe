<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007191540 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the bridge host samples table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_host_samples (id UUID NOT NULL, sampled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cpu_pct JSON NOT NULL, mem_used BIGINT NOT NULL, mem_total BIGINT NOT NULL, swap_used BIGINT NOT NULL, battery_pct DOUBLE PRECISION DEFAULT NULL, on_ac BOOLEAN DEFAULT NULL, owner_id UUID NOT NULL, bridge_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5CAF4C6A7E3C61F94948DF55 ON bridge_host_samples (owner_id, bridge_id)');
        $this->addSql('CREATE INDEX idx_bridge_host_samples_bridge_sampled ON bridge_host_samples (bridge_id, sampled_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_host_samples_owner_bridge_sampled ON bridge_host_samples (owner_id, bridge_id, sampled_at)');
        $this->addSql('ALTER TABLE bridge_host_samples ADD CONSTRAINT FK_5CAF4C6A7E3C61F94948DF55 FOREIGN KEY (owner_id, bridge_id) REFERENCES bridges (owner_id, id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_host_samples');
    }
}
