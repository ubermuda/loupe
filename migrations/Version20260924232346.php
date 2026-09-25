<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924232346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record on a card and comment link whether the comment created its card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments ADD created_card BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments DROP created_card');
    }
}
