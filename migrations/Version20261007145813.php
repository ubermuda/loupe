<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007145813 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create insights_analyses and insights_proposals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insights_analyses (id UUID NOT NULL, work_request_id UUID DEFAULT NULL, document_id UUID DEFAULT NULL, state VARCHAR(20) NOT NULL, reason VARCHAR(64) DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, scope JSON NOT NULL, topic VARCHAR(20) NOT NULL, question TEXT DEFAULT NULL, model VARCHAR(64) NOT NULL, effort VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9DC19916166D1F9C ON insights_analyses (project_id)');
        $this->addSql('CREATE TABLE insights_proposals (id UUID NOT NULL, state VARCHAR(20) NOT NULL, dismiss_reason VARCHAR(500) DEFAULT NULL, card_id UUID DEFAULT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, payload JSON DEFAULT NULL, estimated_saving VARCHAR(200) DEFAULT NULL, position INT NOT NULL, analysis_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7E30B4487941003F ON insights_proposals (analysis_id)');
        $this->addSql('ALTER TABLE insights_analyses ADD CONSTRAINT FK_9DC19916166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE insights_proposals ADD CONSTRAINT FK_7E30B4487941003F FOREIGN KEY (analysis_id) REFERENCES insights_analyses (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_analyses DROP CONSTRAINT FK_9DC19916166D1F9C');
        $this->addSql('ALTER TABLE insights_proposals DROP CONSTRAINT FK_7E30B4487941003F');
        $this->addSql('DROP TABLE insights_analyses');
        $this->addSql('DROP TABLE insights_proposals');
    }
}
