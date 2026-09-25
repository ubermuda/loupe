<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925014908 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Delete every site-review comment saved before feedback moved onto cards';
    }

    public function up(Schema $schema): void
    {
        // The anchors and the card links cascade from the comment.
        $this->addSql('DELETE FROM site_review_comments');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted site-review comments cannot be restored.');
    }
}
