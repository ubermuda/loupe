<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008195155 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the notes digest to the site review check state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_site_review_check_states ADD notes_digest VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_site_review_check_states DROP notes_digest');
    }
}
