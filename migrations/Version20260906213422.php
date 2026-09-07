<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906213422 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add site_review_comments.context, the opaque marker the widget embed carries';
    }

    /**
     * Nullable, and null on every deployment that does not set the variable.
     * A plain column rather than a relation: the value names a row SiteReview
     * cannot see, and only the module that wrote the marker can resolve it.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_comments ADD context VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_comments DROP context');
    }
}
