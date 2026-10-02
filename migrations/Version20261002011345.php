<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002011345 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Delete the card holds that stops wrote';
    }

    public function up(Schema $schema): void
    {
        // Every hold so far came from a stop, and a stop no longer pauses the card.
        $this->addSql('DELETE FROM bridge_card_holds');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The deleted holds are gone.');
    }
}
