<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007194103 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the insights bucket rule table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_bucket_rules (id UUID NOT NULL, pattern VARCHAR(120) NOT NULL, bucket VARCHAR(64) NOT NULL, position INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DAEEBB42166D1F9C ON insights_bucket_rules (project_id)');
        $this->addSql('CREATE INDEX idx_insights_bucket_rule_position ON insights_bucket_rules (project_id, position)');
        $this->addSql('ALTER TABLE insights_bucket_rules ADD CONSTRAINT FK_DAEEBB42166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE insights_bucket_rules');
    }
}
