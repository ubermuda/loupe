<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911182411 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rename the site_review.push.enabled flag row to agent.push.enabled';
    }

    /**
     * The outbox now carries every producer's events, not only site review, so
     * the flag that gates the push moved with it. An upgraded instance still
     * holds the old row, and every check treats a missing flag as off, so
     * without this rename push would stop on exactly the instances that had it
     * on.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE feature_flag SET name = 'agent.push.enabled' WHERE name = 'site_review.push.enabled'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE feature_flag SET name = 'site_review.push.enabled' WHERE name = 'agent.push.enabled'");
    }
}
