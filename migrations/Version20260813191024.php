<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813191024 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Promote draft site-review comments to pending: the widget saves live, so the draft status is gone';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE site_review_comments SET status = 'pending' WHERE status = 'draft'");
    }

    /**
     * Not reversible: once promoted, nothing distinguishes a former draft from a
     * comment that was already pending.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
