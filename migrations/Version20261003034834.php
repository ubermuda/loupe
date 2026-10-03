<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003034834 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the session a work request resumes';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD resume_session_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP resume_session_id');
    }
}
