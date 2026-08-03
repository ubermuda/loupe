<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803132229 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add replacement to comments, carrying a strike or a suggested rewording';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // Nullable with no default on purpose: NULL is "no edit proposed", which is
        // what every existing comment is, and it must stay distinct from the empty
        // string, which means "strike this passage".
        $this->addSql('ALTER TABLE comments ADD replacement TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments DROP replacement');
    }
}
