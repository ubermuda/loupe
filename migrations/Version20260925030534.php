<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925030534 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record on a card and comment link the title the comment gave the card it created';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments ADD created_title VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_site_review_comments DROP created_title');
    }
}
