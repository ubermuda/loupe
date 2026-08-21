<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803012924 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the document_references join table, linking a document to the documents it points at.';
    }

    public function up(Schema $schema): void
    {
        // The composite primary key is what makes a repeated link impossible:
        // the same pair twice is the same reference, not a second one.
        $this->addSql('CREATE TABLE document_references (source_document_id UUID NOT NULL, target_document_id UUID NOT NULL, PRIMARY KEY (source_document_id, target_document_id))');
        $this->addSql('CREATE INDEX IDX_3EDD86CAFF402897 ON document_references (source_document_id)');
        $this->addSql('CREATE INDEX IDX_3EDD86CA4405373D ON document_references (target_document_id)');
        $this->addSql('ALTER TABLE document_references ADD CONSTRAINT FK_3EDD86CAFF402897 FOREIGN KEY (source_document_id) REFERENCES documents (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_references ADD CONSTRAINT FK_3EDD86CA4405373D FOREIGN KEY (target_document_id) REFERENCES documents (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_references DROP CONSTRAINT FK_3EDD86CAFF402897');
        $this->addSql('ALTER TABLE document_references DROP CONSTRAINT FK_3EDD86CA4405373D');
        $this->addSql('DROP TABLE document_references');
    }
}
