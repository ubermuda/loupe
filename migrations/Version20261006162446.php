<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006162446 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the subject pull request to workflow rule states';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD subject_pull_request_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP subject_pull_request_id');
    }
}
