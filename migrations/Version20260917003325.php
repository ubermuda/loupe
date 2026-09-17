<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917003325 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store explicit inbox review targets and their results';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_reviews (id UUID NOT NULL, target_kind VARCHAR(20) NOT NULL, target_label TEXT NOT NULL, verdict VARCHAR(20) DEFAULT NULL, note TEXT DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reviewed_version_number INT DEFAULT NULL, document_id UUID DEFAULT NULL, pull_request_id UUID DEFAULT NULL, reviewer_id UUID DEFAULT NULL, document_review_id UUID DEFAULT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5C44EDF0C33F7837 ON inbox_reviews (document_id)');
        $this->addSql('CREATE INDEX IDX_5C44EDF04CE0BF7E ON inbox_reviews (pull_request_id)');
        $this->addSql('CREATE INDEX IDX_5C44EDF070574616 ON inbox_reviews (reviewer_id)');
        $this->addSql('CREATE INDEX IDX_5C44EDF02C16BE3A ON inbox_reviews (document_review_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C44EDF0126F525E ON inbox_reviews (item_id)');
        $this->addSql('ALTER TABLE inbox_reviews ADD CONSTRAINT FK_5C44EDF0C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_reviews ADD CONSTRAINT FK_5C44EDF04CE0BF7E FOREIGN KEY (pull_request_id) REFERENCES board_card_pull_requests (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_reviews ADD CONSTRAINT FK_5C44EDF070574616 FOREIGN KEY (reviewer_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_reviews ADD CONSTRAINT FK_5C44EDF02C16BE3A FOREIGN KEY (document_review_id) REFERENCES reviews (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_reviews ADD CONSTRAINT FK_5C44EDF0126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_reviews DROP CONSTRAINT FK_5C44EDF0C33F7837');
        $this->addSql('ALTER TABLE inbox_reviews DROP CONSTRAINT FK_5C44EDF04CE0BF7E');
        $this->addSql('ALTER TABLE inbox_reviews DROP CONSTRAINT FK_5C44EDF070574616');
        $this->addSql('ALTER TABLE inbox_reviews DROP CONSTRAINT FK_5C44EDF02C16BE3A');
        $this->addSql('ALTER TABLE inbox_reviews DROP CONSTRAINT FK_5C44EDF0126F525E');
        $this->addSql('DROP TABLE inbox_reviews');
    }
}
