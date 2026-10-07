<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007145539 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create insights_project_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_project_settings (id UUID NOT NULL, default_model VARCHAR(64) DEFAULT NULL, default_effort VARCHAR(16) DEFAULT NULL, collect_full_text BOOLEAN DEFAULT false NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FF086918166D1F9C ON insights_project_settings (project_id)');
        $this->addSql('ALTER TABLE insights_project_settings ADD CONSTRAINT FK_FF086918166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_project_settings DROP CONSTRAINT FK_FF086918166D1F9C');
        $this->addSql('DROP TABLE insights_project_settings');
    }
}
